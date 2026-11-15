<?php

// Self-check routing notulen vs pertanyaan lanjutan + tautan media terakhir.
// Run: docker exec ai-chat-backend php tests/chat_routing_check.php

require __DIR__.'/../vendor/autoload.php';

use App\Http\Controllers\Api\ChatController;
use App\Models\Media;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$fail = static function (string $msg): never {
    fwrite(STDERR, "FAIL: $msg\n");
    exit(1);
};

$user = User::create([
    'name' => 'Chat Routing Check',
    'email' => 'chat-routing-'.bin2hex(random_bytes(4)).'@local.test',
    'password' => Hash::make('secret123'),
    'api_token' => 'check-'.bin2hex(random_bytes(8)),
]);

$media = Media::create([
    'user_id' => $user->id,
    'role' => 'assistant',
    'kind' => 'file',
    'filename' => 'Notulen.docx',
    'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'size' => 1,
    'path' => 'media/'.$user->id.'/check.docx',
]);

try {
    auth()->login($user);
    $controller = new ChatController();
    $notulen = new ReflectionMethod($controller, 'isNotulenRequest');
    $notulen->setAccessible(true);
    $lastMedia = new ReflectionMethod($controller, 'lastMediaReply');
    $lastMedia->setAccessible(true);

    if ($notulen->invoke($controller, 'Tolong buat notulensi rapat ini') !== true) {
        $fail('permintaan notulen baru harus memakai prompt notulen');
    }
    if ($notulen->invoke($controller, 'mana notulensi dalam bentuk DOCXnya, saya hanya minta docx', [['role' => 'assistant', 'content' => 'x']]) !== false) {
        $fail('pertanyaan lanjutan tidak boleh dianggap permintaan susun baru');
    }
    if ($notulen->invoke($controller, 'buat ulang notulensinya', [['role' => 'assistant', 'content' => 'x']]) !== true) {
        $fail('permintaan susun ulang harus tetap memakai prompt notulen');
    }

    $history = [['role' => 'assistant', 'content' => '✔ Notulen dibuat: [Notulen.docx](/api/media/'.$media->id.')']];
    $reply = $lastMedia->invoke($controller, 'mana docxnya', $history);
    if ($reply !== '✔ Berkas terakhir: [Notulen.docx](/api/media/'.$media->id.')') {
        $fail('pertanyaan file harus mengembalikan media terakhir');
    }
    if ($lastMedia->invoke($controller, 'mana docxnya', [['role' => 'assistant', 'content' => '[x](/api/media/99999999)']]) !== null) {
        $fail('media yang tidak ada tidak boleh dikembalikan');
    }
    if ($lastMedia->invoke($controller, 'halo', $history) !== null) {
        $fail('pesan biasa tidak boleh memakai shortcut media');
    }

    $quote = new ReflectionMethod($controller, 'replyQuote');
    $quote->setAccessible(true);
    if ($quote->invoke($controller, ['role' => 'assistant', 'content' => '  isi  ']) !== "[Membalas pesan Hermes: \"isi\"]\n\n") {
        $fail('kutipan balasan Hermes salah');
    }
    if ($quote->invoke($controller, ['role' => 'user', 'content' => str_repeat('a', 700)])
        !== '[Membalas pesan saya: "'.str_repeat('a', 600).'"]'."\n\n") {
        $fail('kutipan balasan harus dipotong 600 karakter');
    }
    if ($quote->invoke($controller, null) !== '') {
        $fail('tanpa balasan tidak boleh ada kutipan');
    }

    echo "OK chat routing\n";
} finally {
    $media->delete();
    $user->delete();
}
