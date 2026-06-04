<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Support;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Notifications\Notifiable;

class DefaultNotifiable
{
    use Notifiable;

    public function __construct(private readonly Config $config)
    {
    }

    public function routeNotificationForMail(): ?string
    {
        return $this->config->get('stream-backup.notifications.mail.to');
    }

    public function routeNotificationForSlack(): ?string
    {
        return $this->config->get('stream-backup.notifications.slack.webhook_url');
    }
}
