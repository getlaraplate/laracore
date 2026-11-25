<?php

declare(strict_types=1);

namespace Modules\Core\Console;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\search;
use function Laravel\Prompts\select;

use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Modules\Core\Console\Concerns\PromptsForPassword;
use Modules\Core\Locking\Locked;
use Modules\Core\Models\User;
use Modules\Core\Overrides\Command;
use Override;
use Symfony\Component\Console\Command\Command as BaseCommand;
use Throwable;

final class ResetUserPasswordCommand extends Command
{
    use PromptsForPassword;

    private const string MODE_TYPE = 'type';

    private const string MODE_EMAIL = 'email';

    /**
     * The name and signature of the console command.
     */
    #[Override]
    protected $signature = 'auth:reset-password';

    /**
     * The console command description.
     */
    #[Override]
    protected $description = 'Reset the password of an existing user <fg=green>(⚡ Modules\Core)</fg=green>';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            /** @var class-string<User> $user_class */
            $user_class = user_class();

            $user_id = search(
                'User',
                fn (string $value): array => $value === '' ? [] : $user_class::query()
                    ->whereAny(['email', 'username'], 'like', '%' . $value . '%')
                    ->limit(10)
                    ->get()
                    ->mapWithKeys(fn (User $user): array => [$this->attributeAsString($user, $user->getKeyName()) => sprintf('%s (%s)', $this->attributeAsString($user, 'email'), $this->attributeAsString($user, 'username'))])
                    ->all(),
                'Search by email or username',
            );

            $user = $user_class::query()->find($user_id);

            if (! $user instanceof User) {
                $this->error('User not found');

                return BaseCommand::FAILURE;
            }

            if ($user->isServiceAccount()) {
                $this->error('Service accounts have no usable password: they authenticate with API tokens only');

                return BaseCommand::FAILURE;
            }

            if (! confirm(sprintf('Reset the password of %s?', $this->attributeAsString($user, 'email')), false)) {
                $this->output->info('Nothing changed');

                return BaseCommand::SUCCESS;
            }

            $mode = select('How do you want to reset it?', [
                self::MODE_TYPE => 'Enter a new password',
                self::MODE_EMAIL => 'Send a password reset email',
            ]);

            return $mode === self::MODE_EMAIL ? $this->sendResetLink($user) : $this->setPassword($user);
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return BaseCommand::FAILURE;
        }
    }

    /**
     * Ask for the new password, or generate one when left blank, and rotate the remember token.
     */
    private function setPassword(User $user): int
    {
        ['password' => $new_password, 'generated' => $is_generated] = $this->promptPassword(['password' => ['required', 'string', Password::default()]], 'New password');

        $user->forceFill([
            'password' => $new_password,
            'remember_token' => Str::random(60),
        ]);
        Locked::withoutGuard(fn () => $user->save());

        $this->output->info(sprintf('Password reset for %s', $this->attributeAsString($user, 'email')));

        if ($is_generated) {
            $this->line(sprintf('Generated password: <fg=yellow>%s</>', $new_password));
        }

        return BaseCommand::SUCCESS;
    }

    /**
     * Send the reset link through the password broker, the same flow as the forgot-password page.
     */
    private function sendResetLink(User $user): int
    {
        $status = PasswordBroker::broker()->sendResetLink(['email' => $this->attributeAsString($user, 'email')]);

        if ($status !== PasswordBroker::RESET_LINK_SENT) {
            $this->error(__($status));

            return BaseCommand::FAILURE;
        }

        $this->output->info(sprintf('Password reset email sent to %s', $this->attributeAsString($user, 'email')));

        return BaseCommand::SUCCESS;
    }

    /**
     * Read a scalar user attribute for console output.
     */
    private function attributeAsString(User $user, string $attribute): string
    {
        $value = $user->getAttribute($attribute);

        return is_scalar($value) ? (string) $value : '';
    }
}
