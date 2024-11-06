<?php
namespace App\Notifications;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

class RegistrationSuccess extends Notification implements ShouldQueue
{
    use Queueable;
    protected $notification_array;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct($notification_array)
    {
        $this->notification_array = $notification_array;

    }
    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return ['mail'];
    }
    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable)
    {
        if (!$this->notification_array['segmentize'])
        {
            $url = url('/brain-map-viewer/' . $this->notification_array['brain_map_id'] . '/');
            $url = str_replace('http://', 'https://', $url);
            $url = str_replace('localhost/tool.' . getenv('SERVER_HOSTNAME'), 'tool.' . getenv('SERVER_HOSTNAME'), $url);

            return (new MailMessage)
                ->subject('Registration of your MRI files completed')
                ->action('View results', url($url))
                ->line('Please proceed with segmentation step.')
                ->line('Thank you for using our application!');
        }
        else {
            $url = url('/brain-map-viewer/' . $this->notification_array['brain_map_id'] . '/');
            $url = str_replace('http://', 'https://', $url);
            $url = str_replace('localhost/tool.' . getenv('SERVER_HOSTNAME'), 'tool.' . getenv('SERVER_HOSTNAME'), $url);

            return (new MailMessage)
                ->subject('Registration and segmentation of your MRI files completed')
                ->line('Please view the results of the segmentation.')
                ->action('View results', url($url))
                ->line('Bear in mind that an automated segmentation is an aid and should not be considered as a conclusive diagnosis.')
                ->line('Thank you for using our application!');
        }
    }
    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toArray($notifiable)
    {
        return [
            //
        ];
    }
}
