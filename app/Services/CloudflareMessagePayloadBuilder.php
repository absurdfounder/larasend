<?php

namespace App\Services;

use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\Header\HeaderConsts;
use ZBateson\MailMimeParser\Header\IdHeader;
use ZBateson\MailMimeParser\Header\Part\AddressPart;
use ZBateson\MailMimeParser\IMessage;
use ZBateson\MailMimeParser\MailMimeParser;

class CloudflareMessagePayloadBuilder
{
    /** @var array<string, string> */
    private const ALLOWED_HEADERS = [
        'in-reply-to' => 'In-Reply-To',
        'references' => 'References',
        'thread-index' => 'Thread-Index',
        'thread-topic' => 'Thread-Topic',
        'list-unsubscribe' => 'List-Unsubscribe',
        'list-unsubscribe-post' => 'List-Unsubscribe-Post',
        'list-id' => 'List-Id',
        'list-archive' => 'List-Archive',
        'list-help' => 'List-Help',
        'list-owner' => 'List-Owner',
        'list-post' => 'List-Post',
        'list-subscribe' => 'List-Subscribe',
        'precedence' => 'Precedence',
        'auto-submitted' => 'Auto-Submitted',
        'content-language' => 'Content-Language',
        'keywords' => 'Keywords',
        'comments' => 'Comments',
        'importance' => 'Importance',
        'priority' => 'Priority',
        'sensitivity' => 'Sensitivity',
        'organization' => 'Organization',
        'require-recipient-valid-since' => 'Require-Recipient-Valid-Since',
        'expires' => 'Expires',
        'reply-by' => 'Reply-By',
        'archived-at' => 'Archived-At',
    ];

    public function __construct(private MailMimeParser $parser) {}

    /**
     * @param  array{from: string, recipients: array<int, string>, to: array<int, string>, cc: array<int, string>, bcc: array<int, string>}  $envelope
     * @return array<string, mixed>
     */
    public function build(string $mime, array $envelope): array
    {
        $message = $this->parser->parse($mime, autoClose: true);
        $payload = [
            'from' => $this->sender($message, $envelope['from']),
            'to' => $this->recipients($message, HeaderConsts::TO, $envelope['to']),
            'cc' => $this->recipients($message, HeaderConsts::CC, $envelope['cc']),
            'bcc' => $this->recipients($message, HeaderConsts::BCC, $envelope['bcc']),
            'reply_to' => $this->firstAddress($message, HeaderConsts::REPLY_TO),
            'subject' => (string) $message->getSubject(),
            'text' => $message->getTextContent(),
            'html' => $message->getHtmlContent(),
            'headers' => $this->headers($message),
            'attachments' => $this->attachments($message),
        ];

        return array_filter(
            $payload,
            fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '',
        );
    }

    /** @return string|array{address: string, name: string} */
    private function sender(IMessage $message, string $envelopeFrom): string|array
    {
        $header = $message->getHeader(HeaderConsts::FROM);

        if (! $header instanceof AddressHeader) {
            return $envelopeFrom;
        }

        $address = $header->getAddresses()[0] ?? null;

        if (! $address instanceof AddressPart || strcasecmp($address->getEmail(), $envelopeFrom) !== 0) {
            return $envelopeFrom;
        }

        return $this->formatAddress($address);
    }

    /**
     * @param  array<int, string>  $envelopeRecipients
     * @return array<int, string|array{address: string, name: string}>
     */
    private function recipients(IMessage $message, string $headerName, array $envelopeRecipients): array
    {
        $header = $message->getHeader($headerName);
        $named = [];

        if ($header instanceof AddressHeader) {
            foreach ($header->getAddresses() as $address) {
                $named[strtolower($address->getEmail())] = $this->formatAddress($address);
            }
        }

        return array_map(
            fn (string $email): string|array => $named[strtolower($email)] ?? $email,
            $envelopeRecipients,
        );
    }

    /** @return string|array{address: string, name: string}|null */
    private function firstAddress(IMessage $message, string $headerName): string|array|null
    {
        $header = $message->getHeader($headerName);

        if (! $header instanceof AddressHeader) {
            return null;
        }

        $address = $header->getAddresses()[0] ?? null;

        return $address instanceof AddressPart ? $this->formatAddress($address) : null;
    }

    /** @return string|array{address: string, name: string} */
    private function formatAddress(AddressPart $address): string|array
    {
        return $address->getName() === ''
            ? $address->getEmail()
            : ['address' => $address->getEmail(), 'name' => $address->getName()];
    }

    /** @return array<string, string> */
    private function headers(IMessage $message): array
    {
        $headers = [];

        foreach ($message->getAllHeaders() as $header) {
            $name = $header->getName();
            $canonicalName = self::ALLOWED_HEADERS[strtolower($name)] ?? null;

            if ($canonicalName === null && preg_match('/^X-[A-Za-z0-9\-_]+$/i', $name) === 1) {
                $canonicalName = $name;
            }

            if ($canonicalName === null || array_key_exists($canonicalName, $headers)) {
                continue;
            }

            $value = $header instanceof IdHeader
                ? collect($header->getIds())->map(fn (string $id): string => '<'.trim($id, '<>').'>')->implode(' ')
                : trim($header->getDecodedValue());

            if ($value !== '') {
                $headers[$canonicalName] = $value;
            }
        }

        return $headers;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function attachments(IMessage $message): array
    {
        return collect($message->getAllAttachmentParts())
            ->map(function ($part): array {
                $contentId = trim((string) $part->getContentId(), '<>');
                $disposition = strtolower((string) $part->getContentDisposition('attachment'));
                $disposition = $disposition === 'inline' && $contentId !== '' ? 'inline' : 'attachment';

                return array_filter([
                    'content' => base64_encode((string) $part->getContent()),
                    'filename' => $part->getFilename() ?: 'attachment',
                    'type' => $part->getContentType('application/octet-stream'),
                    'disposition' => $disposition,
                    'content_id' => $disposition === 'inline' ? $contentId : null,
                ], fn (?string $value): bool => $value !== null && $value !== '');
            })
            ->values()
            ->all();
    }
}
