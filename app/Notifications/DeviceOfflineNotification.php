<?php

namespace App\Notifications;

use App\Models\SyncDevice;
use App\Support\DisplayTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

class DeviceOfflineNotification extends ReelFlowNotification
{
    public function __construct(public readonly SyncDevice $device)
    {
        parent::__construct();
    }

    public function type(): string
    {
        return 'device_offline';
    }

    public function related(): ?Model
    {
        return $this->device;
    }

    public function subject(): string
    {
        return "[ReelFlow] Sync agent offline: {$this->device->name}";
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subject())
            ->line('**Device:** '.$this->device->name)
            ->line('**Last seen:** '.DisplayTime::format($this->device->last_seen_at))
            ->line('Videos that are already uploaded keep publishing on schedule. New local videos will sync when the agent is running again.')
            ->action('View sync devices', route('devices.index'));
    }
}
