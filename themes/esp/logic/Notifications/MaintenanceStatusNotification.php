<?php

namespace EspTheme\Logic\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Schema;

class MaintenanceStatusNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected string $subject,
        protected string $message,
        protected ?string $actionUrl = null,
    ) {
    }

    public function via($notifiable): array
    {
        $channels = ['mail'];
        if (class_exists('Illuminate\\Notifications\\DatabaseNotification') && Schema::hasTable('notifications')) {
            $channels[] = 'database';
        }

        return $channels;
    }

    public function toMail($notifiable): MailMessage
    {
        $mail = (new MailMessage())
            ->subject($this->subject)
            ->line($this->message);

        if ($this->actionUrl) {
            $mail->action(trans('esp::maintenance.mail_action'), $this->actionUrl);
        }

        return $mail;
    }

    public function toArray($notifiable): array
    {
        return [
            'subject' => $this->subject,
            'message' => $this->message,
            'url' => $this->actionUrl,
        ];
    }
}
