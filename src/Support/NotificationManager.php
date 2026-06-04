<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Support;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Notifications\Notification;

class NotificationManager
{
    public function __construct(private readonly Config $config)
    {
    }

    public function notify(Notification $notification): void
    {
        $notifiableClass = $this->config->get('stream-backup.notifications.notifiable');

        if ($notifiableClass && class_exists($notifiableClass)) {
            $notifiable = app($notifiableClass);
            $notifiable->notify($notification);
        }
    }
}
