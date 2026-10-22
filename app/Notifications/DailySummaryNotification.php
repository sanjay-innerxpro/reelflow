<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

class DailySummaryNotification extends ReelFlowNotification
{
    /**
     * @param  array<string, mixed>  $summary
     */
    public function __construct(public readonly array $summary)
    {
        parent::__construct();
    }

    public function type(): string
    {
        return 'daily_summary';
    }

    public function subject(): string
    {
        return '[ReelFlow] Daily summary for '.$this->summary['date'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $s = $this->summary;
        $mail = (new MailMessage)
            ->subject($this->subject())
            ->greeting('Publishing summary — '.$s['date'])
            ->line("Published: **{$s['published']}** · Failed: **{$s['failed']}** · Retrying: **{$s['retrying']}** · Scheduled next 24h: **{$s['upcoming']}**")
            ->line("Videos ready to schedule: **{$s['ready_videos']}** · New videos synced today: **{$s['new_videos']}**");

        foreach ($s['by_platform'] as $platform => $row) {
            $mail->line("{$platform}: {$row['published']} published, {$row['failed']} failed");
        }

        foreach (array_slice($s['failures'], 0, 10) as $failure) {
            $mail->line("✗ {$failure}");
        }

        foreach (array_slice($s['links'], 0, 15) as $link) {
            $mail->line("✓ {$link}");
        }

        if ($s['account_problems']) {
            $mail->line('Accounts needing attention: '.implode(', ', $s['account_problems']));
        }

        return $mail->action('Open dashboard', route('dashboard'));
    }
}
