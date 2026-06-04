<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Notifications;

use Ahmednour\StreamBackup\Models\Backup;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\SlackMessage;

class BackupFailed extends Notification
{
    use Queueable;

    public function __construct(
        public readonly ?Backup $backup,
        public readonly string $errorMessage
    ) {
    }

    public function via($notifiable): array
    {
        $channels = [];
        if (method_exists($notifiable, 'routeNotificationForMail') && $notifiable->routeNotificationForMail() !== null) {
            $channels[] = 'mail';
        }
        if (method_exists($notifiable, 'routeNotificationForSlack') && $notifiable->routeNotificationForSlack() !== null) {
            $channels[] = 'slack';
        }

        if (method_exists($notifiable, 'backupNotificationChannels')) {
            return $notifiable->backupNotificationChannels($this);
        }

        return $channels;
    }

    public function toMail($notifiable): MailMessage
    {
        $dbName = $this->backup ? $this->backup->database_name : 'Unknown';

        return (new MailMessage)
            ->error()
            ->subject("Backup Failed: {$dbName}")
            ->line("A backup for the database `{$dbName}` has failed.")
            ->line("Error Details:")
            ->line($this->errorMessage);
    }

    public function toSlack($notifiable): SlackMessage
    {
        $dbName = $this->backup ? $this->backup->database_name : 'Unknown';

        return (new SlackMessage)
            ->error()
            ->text("❌ *Backup Failed* for Database: {$dbName}\n\n```\n{$this->errorMessage}\n```");
    }
}
