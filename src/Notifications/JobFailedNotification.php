<?php

namespace Storvia\Vantage\Notifications;

use Storvia\Vantage\Models\VantageJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\SlackMessage;
use Illuminate\Notifications\Notification;

class JobFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public VantageJob $jobRun) {}

    public function via($notifiable): array
    {
        $channels = [];

        if (config('vantage.notify.email')) {
            $channels[] = 'mail';
        }

        if (config('vantage.notify.slack_webhook')) {
            $channels[] = 'slack';
        }

        return $channels;
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Queue Job Failed: '.$this->jobRun->job_class)
            ->line('A queued job has failed.')
            ->line('Job: '.$this->jobRun->job_class)
            ->line('Queue: '.$this->jobRun->queue)
            ->line('Connection: '.$this->jobRun->connection)
            ->line('Attempts: '.$this->jobRun->attempt)
            ->line('Exception: '.$this->jobRun->exception_class)
            ->line('Message: '.$this->jobRun->exception_message)
            ->line('- Queue Monitor');
    }

    public function toSlack($notifiable): SlackMessage
    {
        return (new SlackMessage)
            ->error()
            ->content('A queued job has failed!')
            ->attachment(function ($attachment) {
                $attachment->title($this->jobRun->job_class)
                    ->fields([
                        'Queue' => $this->jobRun->queue,
                        'Connection' => $this->jobRun->connection,
                        'Attempts' => $this->jobRun->attempt,
                        'Exception' => $this->jobRun->exception_class,
                    ]);
            });
    }
}
