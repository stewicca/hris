<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class EmployeeNotification extends Notification
{
    /**
     * @param  'leave'|'salary'|'info'  $type
     */
    public function __construct(
        public string $title,
        public string $body,
        public string $type = 'info',
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'type' => $this->type,
        ];
    }
}
