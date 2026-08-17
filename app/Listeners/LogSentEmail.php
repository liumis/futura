<?php



namespace App\Listeners;



use App\Services\EmailLogWriter;

use Illuminate\Mail\Events\MessageSent;



class LogSentEmail

{

    public function handle(MessageSent $event): void

    {

        $message = $event->message;

        $headers = $message->getHeaders();



        if (

            $headers->has('X-Futura-Email-Log')

            && $headers->get('X-Futura-Email-Log')->getBodyAsString() === 'handled'

        ) {

            return;

        }



        EmailLogWriter::logFromSymfonyEmail(

            $message,

            auth()->id(),

            $event->data['mailer'] ?? config('mail.default'),

        );

    }

}

