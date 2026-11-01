<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * One message being sent, by either side.
 *
 * Shared by the guest's form and the owner's, because what makes a
 * message acceptable does not depend on who is writing it. The
 * controller authorises; this only decides whether the thing sent is
 * a message at all.
 */
class MessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The controller authorizes against ConversationPolicy.
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            /*
             * Either words or a picture. Neither is an empty message,
             * which would sit in the thread saying nothing and raise
             * the other side's unread count for it.
             */
            'body' => ['nullable', 'string', 'max:2000', 'required_without:attachments'],

            'attachments' => ['nullable', 'array', 'max:4'],

            /*
             * Pictures only, and checked by content rather than by the
             * extension on the name. "image" asks the file what it is;
             * trusting photo.jpg would accept anything renamed.
             */
            'attachments.*' => [
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required_without' => 'Write a message, or attach a picture.',
            'body.max' => 'That is longer than a message can be. Send it in two.',
            'attachments.max' => 'Four pictures at a time.',
            'attachments.*.image' => 'Only pictures can be attached.',
            'attachments.*.mimes' => 'Pictures must be JPG, PNG or WEBP.',
            'attachments.*.max' => 'Each picture must be under 5 MB.',
        ];
    }

    /** @return array<int, UploadedFile> */
    public function pictures(): array
    {
        return array_values(array_filter(
            (array) $this->file('attachments', []),
            fn ($file) => $file instanceof UploadedFile
        ));
    }

    public function body(): ?string
    {
        $body = $this->validated()['body'] ?? null;

        return is_string($body) && trim($body) !== '' ? $body : null;
    }
}
