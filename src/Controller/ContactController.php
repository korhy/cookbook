<?php

namespace App\Controller;

use App\DTO\ContactDTO;
use App\Form\ContactType;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ContactController extends AbstractController
{
    public function __construct(
        private MailerInterface $mailer,
        #[Autowire('%admin_email%')] private string $adminEmail
    )
    {

    }

    /**
     * @throws TransportExceptionInterface
     */
    #[Route('/contact', name: 'app_contact')]
    public function index(Request $request): Response
    {
        $data = new ContactDTO();
        $form = $this->createForm(ContactType::class, $data);

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->mailer->send((new TemplatedEmail())
                    ->subject('Demande de contact')
                    ->htmlTemplate('email/contact.html.twig')
                    ->from($form->get('email')->getData())
                    ->to($this->adminEmail)
                    ->context(['contact' => $form->getData()]));

                $this->addFlash('success', 'Email send');
                return $this->redirectToRoute('home');
            } catch (\Exception $exception) {
                $this->addFlash('danger', 'Error impossible to send the email');
            }

        }

        return $this->render('contact/index.html.twig', [
            'form' => $form,
        ]);
    }
}
