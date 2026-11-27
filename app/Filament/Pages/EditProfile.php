<?php

declare(strict_types=1);

namespace Modules\Core\Filament\Pages;

use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Modules\Core\Support\DisplayTimezone;
use Override;

/**
 * The profile page of the backoffice: the Filament one, with the language and the timezone the signed in
 * user reads the interface and the dates in. Registered with `->profile()` on the panel, not discovered.
 */
final class EditProfile extends BaseEditProfile
{
    #[Override]
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getNameFormComponent(),
                $this->getEmailFormComponent(),
                Select::make('lang')
                    ->options(self::languageOptions())
                    ->nullable(),
                Select::make('timezone')
                    ->options(DisplayTimezone::options())
                    ->searchable()
                    ->nullable(),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
                $this->getCurrentPasswordFormComponent(),
            ]);
    }

    /**
     * The languages the user may choose, keyed by their code: the option value is the code that is stored.
     *
     * @return array<string, string>
     */
    private static function languageOptions(): array
    {
        $locales = config('app.available_locales', translations());
        $locales = is_array($locales) ? array_values(array_filter($locales, is_string(...))) : [];

        return array_combine($locales, $locales);
    }
}
