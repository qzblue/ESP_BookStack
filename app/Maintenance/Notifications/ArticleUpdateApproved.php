<?php

namespace BookStack\Maintenance\Notifications;

use BookStack\Maintenance\MaintenanceTask;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ArticleUpdateApproved extends Notification
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
            ->subject('文章更新已發布：' . $this->task->page->name)
            ->line('您對《' . $this->task->page->name . '》的更新已通過審核並發布。')
            ->action('查看頁面', $this->task->page->getUrl());
    }
}
