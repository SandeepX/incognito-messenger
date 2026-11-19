<?php

use App\Models\Conversation;
use App\Support\AttachmentUpload;
use App\Support\AvatarUpload;
use App\Support\CallService;
use App\Support\PairKey;
use App\Support\UnsendPolicy;

describe('call duration formatting', function () {
    it('formats seconds as mm:ss', function (int $seconds, string $expected) {
        expect(CallService::formatDuration($seconds))->toBe($expected);
    })->with([
        'zero' => [0, '00:00'],
        'seconds only' => [5, '00:05'],
        'minute and seconds' => [65, '01:05'],
        'exactly one hour' => [3600, '60:00'],
        'large duration' => [3725, '62:05'],
        'negative clamps to zero' => [-10, '00:00'],
    ]);
});

describe('unsend decision', function () {
    it('allows delete for everyone only for the sender before it was seen', function (bool $isSender, bool $seen, bool $allowed) {
        expect(UnsendPolicy::canDeleteForEveryone($isSender, $seen))->toBe($allowed);
    })->with([
        'sender and unseen' => [true, false, true],
        'sender but seen' => [true, true, false],
        'recipient and unseen' => [false, false, false],
        'recipient and seen' => [false, true, false],
    ]);

    it('reports the correct rejection reason', function (bool $isSender, bool $seen, ?string $reason) {
        expect(UnsendPolicy::failureReason($isSender, $seen))->toBe($reason);
    })->with([
        'allowed' => [true, false, null],
        'seen by recipient' => [true, true, UnsendPolicy::SEEN_MESSAGE],
        'not the sender' => [false, false, UnsendPolicy::NOT_SENDER],
        'not the sender and seen' => [false, true, UnsendPolicy::NOT_SENDER],
    ]);
});

describe('pair keys', function () {
    it('is direction independent', function () {
        expect(PairKey::for(9, 3))->toBe(PairKey::for(3, 9))
            ->and(PairKey::for(9, 3))->toBe('3-9');
    });

    it('matches conversation direct keys for both directions', function () {
        expect(Conversation::directKey(7, 2))->toBe('2-7')
            ->and(Conversation::directKey(2, 7))->toBe('2-7');
    });
});

describe('upload helpers', function () {
    it('keeps only a safe basename for client filenames', function (string $original, string $expected) {
        expect(AttachmentUpload::safeFilename($original))->toBe($expected);
    })->with([
        'plain name' => ['report.pdf', 'report.pdf'],
        'path traversal' => ['../../etc/passwd', 'passwd'],
        'windows path' => ['C:\\Users\\me\\photo.jpg', 'photo.jpg'],
        'unsafe characters' => ['my report (final)!.pdf', 'my_report__final__.pdf'],
    ]);

    it('recognises images by mime type', function () {
        expect(AttachmentUpload::isImage('image/png'))->toBeTrue()
            ->and(AttachmentUpload::isImage('application/pdf'))->toBeFalse();
    });

    it('exposes the documented size limits', function () {
        expect(AvatarUpload::MAX_KILOBYTES)->toBe(10240)
            ->and(AttachmentUpload::MAX_KILOBYTES)->toBe(20480);
    });
});
