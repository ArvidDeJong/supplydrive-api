<?php

use Darvis\SupplydriveApi\Message;
use Darvis\SupplydriveApi\MessageStatus;
use Darvis\SupplydriveApi\Tests\TestCase;

it('knows the eight statuses Supplydrive documents, by their Dutch value', function () {
    expect(array_map(fn (MessageStatus $status) => $status->value, MessageStatus::cases()))->toBe([
        'Ontvangen door supplydrive',
        'In verwerking',
        'Aflevering in retry',
        'Afgeleverd',
        'Handmatig afgeleverd',
        'Bericht kon niet afgeleverd worden',
        'Mapping gefaald',
        'Afgeleverd bij tussenpartij',
    ]);
});

it('sorts every status into delivered, failed or pending', function () {
    $delivered = [MessageStatus::Delivered, MessageStatus::DeliveredManually, MessageStatus::DeliveredToIntermediary];
    $failed = [MessageStatus::Undeliverable, MessageStatus::MappingFailed];
    $pending = [MessageStatus::Received, MessageStatus::InProgress, MessageStatus::DeliveryInRetry];

    foreach (MessageStatus::cases() as $status) {
        expect($status->isDelivered())->toBe(in_array($status, $delivered, true), $status->name)
            ->and($status->isFailed())->toBe(in_array($status, $failed, true), $status->name)
            ->and($status->isPending())->toBe(in_array($status, $pending, true), $status->name);
    }
});

it('describes a status in the locale of the application', function () {
    app()->setLocale('en');
    expect(MessageStatus::Delivered->description())->toBe('The message has been delivered.');

    app()->setLocale('nl');
    expect(MessageStatus::Delivered->description())->toBe('Het bericht is succesvol afgeleverd.')
        ->and(MessageStatus::MappingFailed->description())->toStartWith('Er is een inhoudelijk probleem');
});

it('builds a message from an item of the response', function () {
    $message = Message::fromArray(TestCase::message('Aflevering in retry', 'F260010', 42));

    expect($message->id)->toBe(42)
        ->and($message->functionalId)->toBe('F260010')
        ->and($message->status)->toBe('Aflevering in retry')
        ->and($message->interfaceId)->toBe(1000)
        ->and($message->knownStatus())->toBe(MessageStatus::DeliveryInRetry)
        ->and($message->isPending())->toBeTrue()
        ->and($message->isDelivered())->toBeFalse()
        ->and($message->isFailed())->toBeFalse()
        ->and($message->raw)->toBe(TestCase::message('Aflevering in retry', 'F260010', 42));
});

it('keeps a status it does not know as pending with the unknown description', function () {
    app()->setLocale('en');

    $message = Message::fromArray(['id' => 1, 'status' => 'Something new']);

    expect($message->knownStatus())->toBeNull()
        ->and($message->status)->toBe('Something new')
        ->and($message->functionalId)->toBeNull()
        ->and($message->interfaceId)->toBeNull()
        ->and($message->description())->toBe('Unknown status.')
        ->and($message->isPending())->toBeTrue()
        ->and($message->isDelivered())->toBeFalse()
        ->and($message->isFailed())->toBeFalse();
});

it('gives the documented fields and the description as an array', function () {
    app()->setLocale('en');

    expect(Message::fromArray(TestCase::message())->toArray())->toBe([
        'id' => 1,
        'functionalId' => 'F260001',
        'status' => 'Afgeleverd',
        'interfaceId' => 1000,
        'description' => 'The message has been delivered.',
    ]);
});
