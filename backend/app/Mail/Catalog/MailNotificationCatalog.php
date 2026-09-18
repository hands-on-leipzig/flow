<?php

namespace App\Mail\Catalog;

use App\Mail\AdminTestMail;
use App\Mail\PublicOtpMail;
use App\Mail\VolunteerInquiryAcceptedMail;
use App\Mail\VolunteerInquiryDeclinedMail;
use App\Mail\VolunteerInquiryPlannerMail;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;

class MailNotificationCatalog
{
    public const SAMPLE_EVENT = 'FLL Regionalwettbewerb Beispielstadt';

    public const SAMPLE_PERSON = 'Alex Beispiel';

    public const SAMPLE_ROLE = 'Jury';

    public const SAMPLE_OTP = '847291';

    /**
     * @return list<MailNotificationDefinition>
     */
    public function all(): array
    {
        return [
            new MailNotificationDefinition(
                key: 'admin-test',
                name: 'Testversand',
                trigger: 'Im Admin unter E-Mail eine Vorschau an eine Adresse senden.',
                audience: 'Die dort eingetragene Adresse',
                status: MailNotificationDefinition::STATUS_LIVE,
                sample: fn () => new AdminTestMail,
            ),
            new MailNotificationDefinition(
                key: 'public-otp',
                name: 'Anmeldecode',
                trigger: 'Öffentliches Team- oder Helfer:innen-Formular fordert einen Code an.',
                audience: 'Die eingegebene E-Mail-Adresse',
                status: MailNotificationDefinition::STATUS_DRAFT,
                sample: fn () => new PublicOtpMail(
                    eventName: self::SAMPLE_EVENT,
                    code: self::SAMPLE_OTP,
                ),
            ),
            new MailNotificationDefinition(
                key: 'volunteer-inquiry-planner',
                name: 'Neue Helfer:innen-Anfrage',
                trigger: 'Über HERO geht eine Anfrage für eine offene Rolle ein.',
                audience: 'Regionalpartner zum Event (Empfänger noch festzulegen)',
                status: MailNotificationDefinition::STATUS_DRAFT,
                sample: fn () => new VolunteerInquiryPlannerMail(
                    eventName: self::SAMPLE_EVENT,
                    personName: self::SAMPLE_PERSON,
                    role: self::SAMPLE_ROLE,
                ),
            ),
            new MailNotificationDefinition(
                key: 'volunteer-inquiry-accepted',
                name: 'Anfrage übernommen',
                trigger: 'Im Planner wird eine Anfrage auf die Helfer:innenliste übernommen.',
                audience: 'Die anfragende Person',
                status: MailNotificationDefinition::STATUS_DRAFT,
                sample: fn () => new VolunteerInquiryAcceptedMail(
                    eventName: self::SAMPLE_EVENT,
                    personName: self::SAMPLE_PERSON,
                ),
            ),
            new MailNotificationDefinition(
                key: 'volunteer-inquiry-declined',
                name: 'Anfrage abgelehnt',
                trigger: 'Im Planner wird eine Anfrage abgelehnt.',
                audience: 'Die anfragende Person',
                status: MailNotificationDefinition::STATUS_DRAFT,
                sample: fn () => new VolunteerInquiryDeclinedMail(
                    eventName: self::SAMPLE_EVENT,
                ),
            ),
        ];
    }

    public function get(string $key): MailNotificationDefinition
    {
        foreach ($this->all() as $definition) {
            if ($definition->key === $key) {
                return $definition;
            }
        }

        throw new InvalidArgumentException("Unknown mail notification [{$key}].");
    }

    /**
     * @return array{
     *     key: string,
     *     name: string,
     *     trigger: string,
     *     audience: string,
     *     status: string,
     *     subject: string,
     *     html: string
     * }
     */
    public function preview(string $key): array
    {
        $definition = $this->get($key);
        $mailable = $definition->mailable();

        return [
            ...$definition->toArray(),
            'subject' => (string) $mailable->envelope()->subject,
            'html' => $mailable->render(),
        ];
    }

    public function sendSample(string $key, string $to): Mailable
    {
        $mailable = $this->get($key)->mailable();
        Mail::to($to)->send($mailable);

        return $mailable;
    }
}
