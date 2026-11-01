<?php

namespace App\Providers;

use App\Models\ActivityLog;
use App\Models\Amenity;
use App\Models\Announcement;
use App\Models\Booking;
use App\Models\Conversation;
use App\Models\Destination;
use App\Models\DestinationCategory;
use App\Models\DestinationImage;
use App\Models\BookingExtra;
use App\Models\OwnerProfile;
use App\Models\Payment;
use App\Models\Resort;
use App\Models\ResortExtra;
use App\Models\ResortImage;
use App\Models\Review;
use App\Models\Room;
use App\Models\RoomImage;
use App\Models\User;
use App\Services\MessagingService;
use App\Support\Asset;
use App\Support\Money;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\View;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Outside production, Eloquent throws on common mistakes instead of hiding them:
        // lazy loading in loops (N+1 queries), filling non-fillable attributes,
        // and reading attributes that were not selected.
        Model::shouldBeStrict(! $this->app->isProduction());

        // @peso($amount) prints a peso amount, e.g. ₱1,500
        Blade::directive('peso', fn (string $expression) => "<?php echo e(".Money::class."::peso({$expression})); ?>");

        /*
         * @vdasset('js/map.js') prints the URL of one of this project's own
         * files with a cache stamp on it.
         *
         * Not named @asset: Blade would then shadow nothing, but a reader
         * seeing @asset would reasonably expect Laravel's asset() helper and
         * its very different behaviour.
         */
        Blade::directive('vdasset', fn (string $expression) => "<?php echo e(".Asset::class."::url({$expression})); ?>");

        // Page links use Bootstrap markup, not Laravel's default Tailwind markup.
        Paginator::useBootstrapFive();

        /*
         * The MTO sidebar badges and the top bar's review bell show the same
         * figures, and both partials render on every office page.
         *
         * Counted once and remembered on the request, because otherwise the
         * second partial runs the same queries again for an answer it already
         * has. Kept on the Request rather than in a static, so each request
         * really does count afresh.
         */
        View::composer(['partials.sidebar-admin', 'partials.topbar-admin'], function ($view): void {
            $request = request();
            $key = 'visitdasol.pending_review_counts';

            if (! $request->attributes->has($key)) {
                $request->attributes->set($key, [
                    'pendingOwnerCount' => User::pendingOwners()->count(),
                    'pendingResortCount' => Resort::pending()->count(),
                ]);
            }

            $view->with($request->attributes->get($key));
        });

        /*
         * The unread count on the header's Messages button.
         *
         * A composer rather than a variable passed from thirty
         * controllers: the navbar renders on every public page, and
         * any controller that forgot would show a guest a badge of
         * zero while a resort was waiting on them.
         *
         * Counted once per request and remembered on it, the same way
         * the office's badges are, because the navbar can render more
         * than once on a page. Signed-out visitors cost no query.
         */
        View::composer(['partials.navbar', 'partials.sidebar-owner'], function ($view): void {
            $user = auth()->user();

            if ($user === null) {
                $view->with('unreadMessages', 0);

                return;
            }

            $request = request();
            $key = 'visitdasol.unread_messages';

            if (! $request->attributes->has($key)) {
                $request->attributes->set(
                    $key,
                    app(MessagingService::class)->unreadCountFor($user)
                );
            }

            $view->with('unreadMessages', $request->attributes->get($key));
        });

        // The password reset email, in Visit Dasol's own words.
        ResetPassword::toMailUsing(function (User $user, string $token): MailMessage {
            $url = route('password.reset', ['token' => $token, 'email' => $user->getEmailForPasswordReset()]);
            $minutes = (int) config('auth.passwords.users.expire', 60);

            /*
             * One letter now, not two. The second was an invitation for
             * environmental fee staff: accounts the office created, with
             * a random secret nobody held, which had never signed in and
             * so had no password to "reset". No account is created that
             * way any more — tourists and owners register themselves and
             * the office account comes from the seeder — so every
             * recipient of this link really is resetting a password.
             */
            return (new MailMessage)
                ->subject('Reset your Visit Dasol password')
                ->greeting('Hello '.$user->first_name.',')
                ->line('Someone asked to reset the password for your Visit Dasol account.')
                ->action('Choose a new password', $url)
                ->line("This link works for {$minutes} minutes and can only be used once.")
                ->line('If you did not ask for this, you can ignore this email. Your password stays the same.');
        });

        // Minimum password strength everywhere Password::defaults() is used
        // (registration and password changes).
        Password::defaults(fn () => Password::min(8)->letters()->numbers());

        // Short, stable names stored in activity_logs.subject_type
        // instead of PHP class names.
        Relation::enforceMorphMap([
            'user' => User::class,
            'owner_profile' => OwnerProfile::class,
            'resort' => Resort::class,
            'resort_image' => ResortImage::class,
            'amenity' => Amenity::class,
            'room' => Room::class,
            'room_image' => RoomImage::class,
            'booking' => Booking::class,
            'destination_category' => DestinationCategory::class,
            'destination' => Destination::class,
            'destination_image' => DestinationImage::class,
            'announcement' => Announcement::class,
            'activity_log' => ActivityLog::class,
            'review' => Review::class,
            'payment' => Payment::class,
            'resort_extra' => ResortExtra::class,
            'booking_extra' => BookingExtra::class,

            /*
             * Messaging. Registered because the audit trail records
             * that a conversation was opened — never what was said in
             * it. The bodies are private between the two parties, and
             * the log is read by the office.
             */
            'conversation' => Conversation::class,

            /*
             * The environmental_fee_* names were here. Their models are
             * gone, and a morph type that names no class is not merely
             * dead: Eloquent treats the stored string as a class name
             * and fatals the moment anything reads one.
             *
             * The audit rows that carried them are NOT deleted — the
             * trail is append-only. The migration that retired the
             * module moved each row's subject into its properties and
             * cleared the pointer, so what happened is still on the
             * record and the log viewer still opens.
             */
        ]);
    }
}
