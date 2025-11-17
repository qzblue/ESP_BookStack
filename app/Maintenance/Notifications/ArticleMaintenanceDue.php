<?php

namespace BookStack\Maintenance\Notifications;

use BookStack\Maintenance\MaintenanceTask;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ArticleMaintenanceDue extends Notification
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
            ->subject('文章維護提醒：' . $this->task->page->name)
            ->line('文章《' . $this->task->page->name . '》需要在 ' . $this->task->next_due_at->format('Y-m-d') . ' 前維護更新。')
            ->line('請登入系統進行內容檢視與更新。');
    }
}
