<?php

namespace BookStack\Maintenance\Notifications;

use BookStack\Maintenance\MaintenanceTask;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ArticleReviewSubmitted extends Notification
{
    use Queueable;

    public function __construct(protected MaintenanceTask $task)
    {
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('文章更新待審核：' . $this->task->page->name)
            ->line('維護人員 ' . $this->task->user->name . ' 已送出《' . $this->task->page->name . '》的更新內容。')
            ->action('前往審核', route('maintenance.index'));
    }
}
