<?php

namespace App\Notifications;

use App\Models\Video;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

class UploadFailuresNotification extends ReelFlowNotification
{
    public function __construct(public readonly Video $video)
    {
        parent::__construct();
    }

    public function type(): string
    {
        return 'upload_failures';
    }

    public function related(): ?Model
    {
        return $this->video;
    }

    public function subject(): string
    {
        return "[ReelFlow] Repeated upload failures: {$this->video->original_filename}";
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->error()
            ->subject($this->subject())
            ->line('**Video:** '.$this->video->original_filename.' ('.$this->video->folder.')')
            ->line('**Failures:** '.$this->video->upload_failures)
            ->line('**Last error:** '.$this->video->processing_error)
            ->action('Open video', route('videos.show', $this->video));
    }
}
