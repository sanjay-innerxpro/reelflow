<?php

namespace App\Notifications;

use App\Enums\PostStatus;
use App\Models\ScheduledPost;
use App\Support\DisplayTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

class PostOutcomeNotification extends ReelFlowNotification
{
    public function __construct(public readonly ScheduledPost $post)
    {
        parent::__construct();
    }

    public function type(): string
    {
        return $this->post->status === PostStatus::Published ? 'post_published' : 'post_failed';
    }

    public function related(): ?Model
    {
        return $this->post;
    }

    public function subject(): string
    {
        $post = $this->post;
        $verb = $post->status === PostStatus::Published ? 'Published' : 'Failed';

        return "[ReelFlow] {$verb} on {$post->platform->label()}: {$post->video->original_filename}";
    }

    public function toMail(object $notifiable): MailMessage
    {
        $post = $this->post->loadMissing(['video', 'socialAccount']);
        $published = $post->status === PostStatus::Published;

        $mail = (new MailMessage)
            ->subject($this->subject())
            ->greeting($published ? 'Your video is live.' : 'A scheduled post failed.')
            ->line('**Platform:** '.$post->platform->label().' — '.$post->socialAccount->displayName())
            ->line('**Video:** '.$post->video->original_filename)
            ->line('**Status:** '.ucfirst($post->status->value).($post->requires_review ? ' (manual check required)' : ''))
            ->line('**Scheduled:** '.DisplayTime::format($post->scheduled_at).' ('.DisplayTime::timezone().')')
            ->line('**Actual:** '.DisplayTime::format($post->published_at ?? $post->updated_at));

        if (! $published) {
            $mail->error()
                ->line('**Error ('.($post->last_error_category?->label() ?? 'unknown').'):** '.$post->last_error_message)
                ->line('Attempts: '.$post->attempt_count.' of '.$post->max_attempts.'.');
        }

        if ($post->post_url) {
            $mail->line('**Post URL:** '.$post->post_url);
        }

        return $mail->action('Open in dashboard', route('posts.show', $post));
    }
}
