<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/RendezVousRules.php';

$passed = 0;
$failed = 0;

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ' (attendu ' . var_export($expected, true) . ', reçu ' . var_export($actual, true) . ')',
        );
    }
}

function appointment(
    string $npi,
    string $dateHeure,
    string $statut = 'confirme',
    string $service = 'PASSEPORT',
    int $id = 0,
): array {
    return [
        'id' => $id,
        'npi' => $npi,
        'service' => $service,
        'dateHeure' => $dateHeure,
        'statut' => $statut,
    ];
}

function resultIs(RuleResult $result, bool $allowed, int $httpStatus, ?string $message = null): void
{
    assertSameValue($allowed, $result->allowed, 'Résultat autorisé');
    assertSameValue($httpStatus, $result->httpStatus, 'Code HTTP');
    assertSameValue($message, $result->message, 'Message de règle');
}

function test(string $name, callable $case): void
{
    global $passed, $failed;

    try {
        $case();
        $passed++;
        echo "OK  $name\n";
    } catch (Throwable $error) {
        $failed++;
        fwrite(STDERR, "KO  $name: {$error->getMessage()}\n");
    }
}

$now = new DateTimeImmutable('2026-11-20 18:00:00', new DateTimeZone('Africa/Porto-Novo'));
$npi = '0614253647';

test('TMA-101 refuse le rendez-vous du samedi 14 novembre', function () use ($npi): void {
    $result = RendezVousRules::book(
        $npi,
        'PASSEPORT',
        '2026-11-14T10:00',
        [],
        new DateTimeImmutable('2026-11-13 18:00:00', new DateTimeZone('Africa/Porto-Novo')),
    );
    resultIs($result, false, 409, 'Le centre est fermé le week-end.');
});

test('TMA-102 refuse le quatrième rendez-vous du 10 novembre à 09:00', function (): void {
    $slot = '2026-11-10T09:00';
    $existing = [
        appointment('0102030401', $slot, 'confirme', 'CNI', 632),
        appointment('0102030402', $slot, 'confirme', 'CNI', 644),
        appointment('0102030403', $slot, 'confirme', 'CNI', 681),
    ];
    $result = RendezVousRules::book(
        '0102030404',
        'CNI',
        $slot,
        $existing,
        new DateTimeImmutable('2026-11-09 18:00:00', new DateTimeZone('Africa/Porto-Novo')),
    );
    resultIs($result, false, 409, 'Ce créneau est complet.');
});

test('TMA-103 retourne une validation NPI lisible', function () use ($now): void {
    $result = RendezVousRules::book('06142A3647', 'CNI', '2026-11-23T09:00', [], $now);
    resultIs($result, false, 400, 'Le NPI doit comporter exactement 10 chiffres.');
});

test('TMA-104 autorise une annulation plus de 24 heures avant', function (): void {
    $result = RendezVousRules::cancel(
        appointment('0102030405', '2026-11-19T10:00'),
        new DateTimeImmutable('2026-11-17 18:00:00', new DateTimeZone('Africa/Porto-Novo')),
    );
    resultIs($result, true, 200);
});

test('TMA-105 refuse de modifier un rendez-vous déjà annulé', function (): void {
    $result = RendezVousRules::changeStatus(
        appointment('0330677555', '2026-11-10T09:00', 'annule'),
        'honore',
    );
    resultIs($result, false, 409, 'Ce rendez-vous est déjà clôturé.');
});

test('TMA-106 place le prochain rendez-vous en tête avant la pagination', function () use ($now, $npi): void {
    $appointments = [
        appointment($npi, '2026-01-05T08:30', 'honore', 'PASSEPORT', 1),
        appointment($npi, '2026-02-12T10:00', 'honore', 'CNI', 2),
        appointment($npi, '2026-03-03T14:30', 'absent', 'PERMIS', 3),
        appointment($npi, '2026-04-21T09:00', 'honore', 'PASSEPORT', 4),
        appointment($npi, '2026-05-08T11:30', 'honore', 'CNI', 5),
        appointment($npi, '2026-05-19T15:00', 'absent', 'PERMIS', 6),
        appointment($npi, '2026-06-02T08:00', 'honore', 'PASSEPORT', 7),
        appointment($npi, '2026-06-16T13:30', 'honore', 'CNI', 8),
        appointment($npi, '2026-07-07T10:30', 'honore', 'PERMIS', 9),
        appointment($npi, '2026-07-28T09:30', 'absent', 'PASSEPORT', 10),
        appointment($npi, '2026-08-11T14:00', 'honore', 'CNI', 11),
        appointment($npi, '2026-09-25T11:00', 'honore', 'PERMIS', 259),
        appointment($npi, '2026-12-02T08:30', 'confirme', 'PASSEPORT', 826),
        appointment($npi, '2026-12-03T08:30', 'annule', 'CNI', 827),
    ];

    $page = RendezVousRules::listForUser($npi, $appointments, 1, $now);
    assertSameValue(14, $page['total'], 'Nombre total de rendez-vous, y compris le futur annulé');
    assertSameValue(10, $page['parPage'], 'Taille de page');
    assertSameValue(826, $page['rendezVous'][0]['id'], 'Le prochain rendez-vous est en tête');
    assertSameValue(10, count($page['rendezVous']), 'Nombre d’éléments sur la première page');
    assertSameValue(827, $page['rendezVous'][1]['id'], 'Un rendez-vous futur annulé ne précède pas le prochain confirmé');
    assertSameValue(259, $page['rendezVous'][2]['id'], 'Les autres rendez-vous suivent par date décroissante');
});

test('TMA-107 interdit deux rendez-vous à venir tous services confondus', function () use ($now): void {
    $existing = [appointment('0102030405', '2026-11-26T10:00', 'confirme', 'PASSEPORT', 800)];
    $result = RendezVousRules::book('0102030405', 'PERMIS', '2026-11-27T09:00', $existing, $now);
    resultIs($result, false, 409, 'Vous avez déjà un rendez-vous à venir.');
});

test('refuse un service inconnu', function () use ($now, $npi): void {
    $result = RendezVousRules::book($npi, 'CARTE', '2026-11-23T09:00', [], $now);
    resultIs($result, false, 400, 'Service inconnu : choisissez PASSEPORT, CNI ou PERMIS.');
});

test('refuse les types de saisie invalides sans erreur interne', function () use ($now, $npi): void {
    resultIs(
        RendezVousRules::book(614253647, 'CNI', '2026-11-23T09:00', [], $now),
        false,
        400,
        'Le NPI doit comporter exactement 10 chiffres.',
    );
    resultIs(
        RendezVousRules::book($npi, [], '2026-11-23T09:00', [], $now),
        false,
        400,
        'Service inconnu : choisissez PASSEPORT, CNI ou PERMIS.',
    );
    resultIs(
        RendezVousRules::book($npi, 'CNI', null, [], $now),
        false,
        400,
        'Date et heure invalides (format attendu : AAAA-MM-JJTHH:MM).',
    );
    resultIs(
        RendezVousRules::changeStatus(appointment($npi, '2026-12-02T09:00'), ['honore']),
        false,
        400,
        'Statut invalide : honore ou absent.',
    );
});

test('refuse une date au format invalide ou impossible', function () use ($now, $npi): void {
    foreach (['2026-11-23 09:00', '2026-02-30T09:00'] as $date) {
        $result = RendezVousRules::book($npi, 'CNI', $date, [], $now);
        resultIs($result, false, 400, 'Date et heure invalides (format attendu : AAAA-MM-JJTHH:MM).');
    }
});

test('refuse une date passée et un créneau hors grille', function () use ($now, $npi): void {
    $past = RendezVousRules::book($npi, 'CNI', '2026-11-20T17:30', [], $now);
    resultIs($past, false, 409, 'Impossible de prendre un rendez-vous dans le passé.');

    $offGrid = RendezVousRules::book($npi, 'CNI', '2026-11-23T16:00', [], $now);
    resultIs($offGrid, false, 409, 'Créneau invalide : de 08:00 à 15:30, toutes les 30 minutes.');
});

test('refuse une annulation à moins de 24 heures et accepte la borne exacte', function (): void {
    $appointment = appointment('0102030405', '2026-11-19T10:00');
    $late = RendezVousRules::cancel(
        $appointment,
        new DateTimeImmutable('2026-11-18 10:00:01', new DateTimeZone('Africa/Porto-Novo')),
    );
    resultIs($late, false, 409, 'Annulation impossible moins de 24 h avant le rendez-vous.');

    $boundary = RendezVousRules::cancel(
        $appointment,
        new DateTimeImmutable('2026-11-18 10:00:00', new DateTimeZone('Africa/Porto-Novo')),
    );
    resultIs($boundary, true, 200);
});

test('refuse une annulation si le rendez-vous est déjà clôturé', function (): void {
    $result = RendezVousRules::cancel(
        appointment('0102030405', '2026-12-02T09:00', 'honore'),
        new DateTimeImmutable('2026-11-20 18:00:00', new DateTimeZone('Africa/Porto-Novo')),
    );
    resultIs($result, false, 409, 'Ce rendez-vous ne peut plus être annulé.');
});

test('refuse un statut demandé inconnu et toute transition depuis un statut final', function (): void {
    $confirmed = appointment('0102030405', '2026-12-02T09:00');
    resultIs(
        RendezVousRules::changeStatus($confirmed, 'annule'),
        false,
        400,
        'Statut invalide : honore ou absent.',
    );
    resultIs(
        RendezVousRules::changeStatus(appointment('0102030405', '2026-12-02T09:00', 'absent'), 'honore'),
        false,
        409,
        'Ce rendez-vous est déjà clôturé.',
    );
});

test('une annulation libère une place dans le créneau', function () use ($now): void {
    $slot = '2026-11-24T09:00';
    $existing = [
        appointment('0102030401', $slot, 'confirme'),
        appointment('0102030402', $slot, 'honore'),
        appointment('0102030403', $slot, 'annule'),
    ];
    $result = RendezVousRules::book('0102030404', 'CNI', $slot, $existing, $now);
    resultIs($result, true, 201);
});

test('accepte le troisième rendez-vous d’un créneau', function () use ($now): void {
    $slot = '2026-11-24T09:00';
    $existing = [
        appointment('0102030401', $slot),
        appointment('0102030402', $slot),
    ];
    $result = RendezVousRules::book('0102030403', 'CNI', $slot, $existing, $now);
    resultIs($result, true, 201);
});

test('accepte un vendredi et refuse un dimanche', function () use ($now, $npi): void {
    $friday = RendezVousRules::book($npi, 'CNI', '2026-11-27T09:00', [], $now);
    resultIs($friday, true, 201);

    $sunday = RendezVousRules::book($npi, 'CNI', '2026-11-22T09:00', [], $now);
    resultIs($sunday, false, 409, 'Le centre est fermé le week-end.');
});

test('la pagination trie chaque page avant de la découper', function () use ($now, $npi): void {
    $appointments = [];
    for ($day = 1; $day <= 12; $day++) {
        $appointments[] = appointment(
            $npi,
            sprintf('2026-09-%02dT10:00', $day),
            'honore',
            'CNI',
            $day,
        );
    }
    $secondPage = RendezVousRules::listForUser($npi, $appointments, 2, $now);
    assertSameValue(2, $secondPage['page'], 'Numéro de page');
    assertSameValue(2, count($secondPage['rendezVous']), 'Éléments sur la deuxième page');
    assertSameValue(2, $secondPage['rendezVous'][0]['id'], 'Tri décroissant conservé sur la seconde page');
});

echo "\nRésultat : $passed réussis, $failed échoués.\n";
exit($failed === 0 ? 0 : 1);
