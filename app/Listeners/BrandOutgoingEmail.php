<?php

namespace App\Listeners;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\View;
use Symfony\Component\Mime\Email;

class BrandOutgoingEmail
{
    public function handle(MessageSending $event): void
    {
        $message = $event->message;

        if (! $message instanceof Email) {
            return;
        }

        $headers = $message->getHeaders();

        if ($headers->has('X-SS-Branded')) {
            return;
        }

        $html = $message->getHtmlBody();
        $text = $message->getTextBody();

        if (is_resource($html)) {
            $html = stream_get_contents($html);
        }

        if (is_resource($text)) {
            $text = stream_get_contents($text);
        }

        if (is_string($html) && $html !== '') {
            $message->html($this->wrapHtml($html));
        } elseif (is_string($text) && $text !== '') {
            $message->html($this->wrapText($text));
        } else {
            return;
        }

        $headers->addTextHeader('X-SS-Branded', '1');
    }

    private function wrapText(string $text): string
    {
        return View::make('emails.wrapper', [
            'content' => nl2br(e($text)),
        ])->render();
    }

    private function wrapHtml(string $html): string
    {
        $header = View::make('emails.partials.header')->render();
        $footer = View::make('emails.partials.footer')->render();

        // Full HTML document: inject header after <body> and footer before </body>.
        if (preg_match('/<body\b[^>]*>/i', $html)) {
            $html = preg_replace('/(<body\b[^>]*>)/i', '$1'.$header, $html, 1);

            return preg_replace('/<\/body>/i', $footer.'</body>', $html, 1) ?? $html;
        }

        // HTML fragment: wrap it in the branded layout.
        return View::make('emails.wrapper', [
            'content' => $html,
        ])->render();
    }
}
