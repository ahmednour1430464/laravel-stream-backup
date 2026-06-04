<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Notifications;

use Ahmednour\StreamBackup\Models\Backup;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\SlackMessage;

class BackupSuccessful extends Notification
{
    use Queueable;

    public function __construct(public readonly Backup $backup)
    {
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

        // Allow customized notifiables to define their own via method behavior.
        if (method_exists($notifiable, 'backupNotificationChannels')) {
            return $notifiable->backupNotificationChannels($this);
        }

        return $channels;
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->success()
            ->subject("Backup Successful: {$this->backup->database_name}")
            ->line("A backup for the database `{$this->backup->database_name}` has completed successfully.")
            ->line("Size: " . number_format((float) ($this->backup->size / 1024 / 1024), 2) . " MB")
            ->line("Duration: {$this->backup->duration} seconds");
    }

    public function toSlack($notifiable): SlackMessage
    {
        $size = number_format((float) ($this->backup->size / 1024 / 1024), 2);
        
        return (new SlackMessage)
            ->success()
            ->text("✅ *Backup Successful*\nDatabase: {$this->backup->database_name}\nSize: {$size} MB\nDuration: {$this->backup->duration}s\nDisk: {$this->backup->disk}");
    }
}
