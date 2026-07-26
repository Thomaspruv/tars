<?php

namespace App\Mail;

use App\Models\Review;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class ReviewGenerated extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Review $review) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Ta revue est prête',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.review-generated',
            with: ['pulseExcerpt' => $this->pulseExcerpt()],
        );
    }

    private function pulseExcerpt(): string
    {
        if (preg_match('/## Le pouls\s*\n(.*?)(\n##|\z)/s', $this->review->generated_content, $matches)) {
            return trim(Str::limit(trim($matches[1]), 280));
        }

        return '';
    }
}
