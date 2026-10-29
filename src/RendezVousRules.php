<?php

declare(strict_types=1);

final class RuleResult
{
    public function __construct(
        public readonly bool $allowed,
        public readonly int $httpStatus,
        public readonly ?string $message = null,
    ) {
    }

    public static function success(int $httpStatus = 200): self
    {
        return new self(true, $httpStatus);
    }

    public static function rejected(int $httpStatus, string $message): self
    {
        return new self(false, $httpStatus, $message);
    }
}

final class RendezVousRules
{
    private const TIMEZONE = 'Africa/Porto-Novo';
    private const SERVICES = ['PASSEPORT', 'CNI', 'PERMIS'];
    private const PAGE_SIZE = 10;

    public static function book(
        mixed $npi,
        mixed $service,
        mixed $dateHeure,
        array $appointments,
        DateTimeImmutable $now,
    ): RuleResult {
        if (!is_string($npi) || !preg_match('/^[0-9]{10}$/D', $npi)) {
            return RuleResult::rejected(400, 'Le NPI doit comporter exactement 10 chiffres.');
        }

        if (!is_string($service) || !in_array($service, self::SERVICES, true)) {
            return RuleResult::rejected(400, 'Service inconnu : choisissez PASSEPORT, CNI ou PERMIS.');
        }

        if (!is_string($dateHeure)) {
            return RuleResult::rejected(
                400,
                'Date et heure invalides (format attendu : AAAA-MM-JJTHH:MM).',
            );
        }

        $requestedDate = self::parseInputDate($dateHeure);
        if ($requestedDate === null) {
            return RuleResult::rejected(
                400,
                'Date et heure invalides (format attendu : AAAA-MM-JJTHH:MM).',
            );
        }

        $now = $now->setTimezone(new DateTimeZone(self::TIMEZONE));
        if ($requestedDate < $now) {
            return RuleResult::rejected(409, 'Impossible de prendre un rendez-vous dans le passé.');
        }

        if ((int) $requestedDate->format('N') > 5) {
            return RuleResult::rejected(409, 'Le centre est fermé le week-end.');
        }

        $minutes = ((int) $requestedDate->format('G') * 60) + (int) $requestedDate->format('i');
        if ($minutes < 480 || $minutes > 930 || $minutes % 30 !== 0) {
            return RuleResult::rejected(
                409,
                'Créneau invalide : de 08:00 à 15:30, toutes les 30 minutes.',
            );
        }

        foreach ($appointments as $appointment) {
            if (
                $appointment['npi'] === $npi
                && $appointment['statut'] === 'confirme'
                && self::appointmentDate($appointment) > $now
            ) {
                return RuleResult::rejected(409, 'Vous avez déjà un rendez-vous à venir.');
            }
        }

        $slotCount = 0;
        foreach ($appointments as $appointment) {
            if (
                $appointment['statut'] !== 'annule'
                && self::appointmentDate($appointment) == $requestedDate
            ) {
                $slotCount++;
            }
        }

        if ($slotCount >= 3) {
            return RuleResult::rejected(409, 'Ce créneau est complet.');
        }

        return RuleResult::success(201);
    }

    public static function cancel(array $appointment, DateTimeImmutable $now): RuleResult
    {
        if ($appointment['statut'] !== 'confirme') {
            return RuleResult::rejected(409, 'Ce rendez-vous ne peut plus être annulé.');
        }

        $now = $now->setTimezone(new DateTimeZone(self::TIMEZONE));
        if (self::appointmentDate($appointment) < $now->modify('+24 hours')) {
            return RuleResult::rejected(409, 'Annulation impossible moins de 24 h avant le rendez-vous.');
        }

        return RuleResult::success();
    }

    public static function changeStatus(array $appointment, mixed $requestedStatus): RuleResult
    {
        if (!is_string($requestedStatus) || !in_array($requestedStatus, ['honore', 'absent'], true)) {
            return RuleResult::rejected(400, 'Statut invalide : honore ou absent.');
        }

        if ($appointment['statut'] !== 'confirme') {
            return RuleResult::rejected(409, 'Ce rendez-vous est déjà clôturé.');
        }

        return RuleResult::success();
    }

    public static function listForUser(
        string $npi,
        array $appointments,
        int $page,
        DateTimeImmutable $now,
    ): array {
        $now = $now->setTimezone(new DateTimeZone(self::TIMEZONE));
        $userAppointments = array_values(array_filter(
            $appointments,
            static fn (array $appointment): bool => $appointment['npi'] === $npi,
        ));

        usort($userAppointments, static function (array $left, array $right) use ($now): int {
            $leftDate = self::appointmentDate($left);
            $rightDate = self::appointmentDate($right);
            $leftUpcoming = $left['statut'] === 'confirme' && $leftDate >= $now;
            $rightUpcoming = $right['statut'] === 'confirme' && $rightDate >= $now;

            if ($leftUpcoming !== $rightUpcoming) {
                return $leftUpcoming ? -1 : 1;
            }

            $dateOrder = $leftDate <=> $rightDate;
            if (!$leftUpcoming) {
                $dateOrder *= -1;
            }

            if ($dateOrder !== 0) {
                return $dateOrder;
            }

            return ($left['id'] ?? 0) <=> ($right['id'] ?? 0);
        });

        $page = max(1, $page);

        return [
            'page' => $page,
            'parPage' => self::PAGE_SIZE,
            'total' => count($userAppointments),
            'rendezVous' => array_slice($userAppointments, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE),
        ];
    }

    private static function parseInputDate(string $value): ?DateTimeImmutable
    {
        if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}$/D', $value)) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i',
            $value,
            new DateTimeZone(self::TIMEZONE),
        );
        $errors = DateTimeImmutable::getLastErrors();

        if (
            $date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d\TH:i') !== $value
        ) {
            return null;
        }

        return $date;
    }

    private static function appointmentDate(array $appointment): DateTimeImmutable
    {
        if (!isset($appointment['dateHeure'])) {
            throw new InvalidArgumentException('Un rendez-vous doit contenir dateHeure.');
        }

        if ($appointment['dateHeure'] instanceof DateTimeImmutable) {
            return $appointment['dateHeure']->setTimezone(new DateTimeZone(self::TIMEZONE));
        }

        if (!is_string($appointment['dateHeure'])) {
            throw new InvalidArgumentException('dateHeure doit être une chaîne ou une date immuable.');
        }

        foreach (['!Y-m-d\TH:i', '!Y-m-d H:i:s'] as $format) {
            $date = DateTimeImmutable::createFromFormat(
                $format,
                $appointment['dateHeure'],
                new DateTimeZone(self::TIMEZONE),
            );
            $errors = DateTimeImmutable::getLastErrors();

            if (
                $date !== false
                && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
                && $date->format(substr($format, 1)) === $appointment['dateHeure']
            ) {
                return $date;
            }
        }

        throw new InvalidArgumentException('dateHeure contient une date invalide.');
    }
}
