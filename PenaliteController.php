<?php
namespace App\Controller;

use App\Entity\Penalite;
use App\Form\PenaliteType;
use App\Repository\AbsenceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Twilio\Rest\Client;

class PenaliteController extends AbstractController
{
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    #[Route('/penalite', name: 'app_penalite_index')]
    public function index(): Response
    {
        // Utilisation de l'EntityManager pour récupérer les pénalités
        $penalites = $this->entityManager->getRepository(Penalite::class)->findAll();

        return $this->render('penalite/index.html.twig', [
            'penalites' => $penalites,
        ]);
    }

    #[Route('/penalite/new', name: 'app_penalite_new')]
    public function new(Request $request, AbsenceRepository $absenceRepository): Response
{
    // Récupère les CIN distincts des absences
    $cinChoices = $absenceRepository->createQueryBuilder('a')
        ->select('a.cin')
        ->distinct()
        ->getQuery()
        ->getResult();

    // Créer une liste de CIN pour le formulaire
    $cinList = array_combine(array_column($cinChoices, 'cin'), array_column($cinChoices, 'cin'));

    // Créer une nouvelle entité Penalite
    $penalite = new Penalite();

    // Créer le formulaire avec les CIN récupérés
    $form = $this->createForm(PenaliteType::class, $penalite, [
        'cin_choices' => $cinList,
    ]);

    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
        // Récupérer le CIN sélectionné
        $cin = $penalite->getCin();

        // Récupérer le nombre d'absences pour ce CIN
        $nbrAbs = $absenceRepository->createQueryBuilder('a')
            ->select('COUNT(a.ID_abs)')
            ->where('a.cin = :cin')
            ->setParameter('cin', $cin)
            ->getQuery()
            ->getSingleScalarResult();

        // Vérification si le nombre d'absences est nul ou incorrect
        if ($nbrAbs === null) {
            $nbrAbs = 0; // Si le nombre d'absences est nul, on le remplace par 0
        }

        // Calcul du seuil d'absence
        $seuilAbs = (float) $nbrAbs / 2;  // Force la division flottante

        // Assigner le seuil à l'entité Penalite
        $penalite->setSeuilAbs($seuilAbs);

        // Vérification du seuil d'absence
        if ($seuilAbs >= 2) {
            // Configuration et envoi du SMS via Twilio
            $sid = ''; // SID Twilio
            $authToken = ''; // Token Twilio
            $fromNumber = ''; // Numéro Twilio

            $client = new Client($sid, $authToken);

            $message = "Le CIN numéro $cin a atteint le seuil d'absence de $seuilAbs.";

            // Envoi du SMS
            $client->messages->create(
                '',  // Remplace par le numéro du destinataire
                [
                    'from' => $fromNumber,
                    'body' => $message,
                ]
            );

            $this->addFlash('success', "Un SMS a été envoyé au numéro correspondant.");
        }

        // Persist l'entité Penalite avec le seuil calculé
        $this->entityManager->persist($penalite);
        $this->entityManager->flush();

        // Rediriger vers la liste des pénalités
        return $this->redirectToRoute('app_penalite_index');
    }

    return $this->render('penalite/new.html.twig', [
        'form' => $form->createView(),
    ]);
}



    #[Route('/penalite/{ID_pen}', name: 'app_penalite_show')]
    public function show(int $ID_pen): Response
    {
        // Récupérer la pénalité par son ID
        $penalite = $this->entityManager
            ->getRepository(Penalite::class)
            ->find($ID_pen);

        if (!$penalite) {
            throw $this->createNotFoundException('Pénalité non trouvée');
        }

        return $this->render('penalite/show.html.twig', [
            'penalite' => $penalite,
        ]);
    }

    #[Route('/penalite/{ID_pen}/edit', name: 'app_penalite_edit')]
    public function edit(int $ID_pen, Request $request): Response
    {
        // Récupérer la pénalité par son ID
        $penalite = $this->entityManager
            ->getRepository(Penalite::class)
            ->find($ID_pen);

        if (!$penalite) {
            throw $this->createNotFoundException('Pénalité non trouvée');
        }

        // Créer le formulaire d'édition
        $form = $this->createForm(PenaliteType::class, $penalite);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            // Rediriger vers la liste des pénalités
            return $this->redirectToRoute('app_penalite_index');
        }

        return $this->render('penalite/edit.html.twig', [
            'form' => $form->createView(),
        ]);
    }
    

    // Route pour récupérer le nombre d'absences par CIN
    #[Route('/penalite/nbr_abs/{cin}', name: 'app_penalite_nbr_abs')]
    public function getNbrAbsByCin(string $cin, AbsenceRepository $absenceRepository): Response
    {
        // Récupérer le nombre d'absences pour ce CIN
        $nbrAbs = $absenceRepository->createQueryBuilder('a')
            ->select('COUNT(a.ID_abs)')
            ->where('a.cin = :cin')
            ->setParameter('cin', $cin)
            ->getQuery()
            ->getSingleScalarResult();

        return $this->json(['nbr_abs' => $nbrAbs]);
    }
    #[Route('/{ID_pen}', name: 'app_penalite_delete', methods: ['POST'])]
    public function delete(Request $request, Penalite $penalite, EntityManagerInterface $entityManager): Response
    {
        // Vérification du token CSRF pour la suppression
        if ($this->isCsrfTokenValid('delete' . $penalite->getIDPen(), $request->request->get('_token'))) {
            // Suppression de la pénalité
            $entityManager->remove($penalite);
            $entityManager->flush();

            // Ajout d'un message flash de succès
            $this->addFlash('success', 'La pénalité a été supprimée avec succès.');
        } else {
            // Ajout d'un message flash d'erreur si le token est invalide
            $this->addFlash('error', 'Erreur lors de la suppression de la pénalité.');
        }

        // Redirection vers la liste des pénalités
        return $this->redirectToRoute('app_penalite_index', [], Response::HTTP_SEE_OTHER);
    }
    
}
