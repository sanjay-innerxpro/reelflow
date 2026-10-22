<?php

namespace App\Notifications;

use App\Models\SocialAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

class AccountProblemNotification extends ReelFlowNotification
{
    public function __construct(public readonly SocialAccount $account)
    {
        parent::__construct();
    }

    public function type(): string
    {
        return 'account_problem';
    }

    public function related(): ?Model
    {
        return $this->account;
    }

    public function subject(): string
    {
        return "[ReelFlow] Action needed: {$this->account->platform->label()} account {$this->account->name}";
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->error()
            ->subject($this->subject())
            ->greeting('Publishing to an account has been paused.')
            ->line('**Platform:** '.$this->account->platform->label())
            ->line('**Account:** '.$this->account->displayName())
            ->line('**Status:** '.ucfirst($this->account->status->value))
            ->line('**Reason:** '.$this->account->status_reason)
            ->line('Scheduled posts for this account will wait until it is reconnected. Failed posts can be retried from the dashboard afterwards.')
            ->action('Reconnect account', route('accounts.index'));
    }
}
