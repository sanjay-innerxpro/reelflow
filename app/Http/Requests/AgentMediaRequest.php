<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AgentMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->get('device') !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxKb = config('reelflow.media.agent_max_upload_kb');

        return [
            'metadata' => ['required', 'json'],
            'thumbnail' => ['required', 'file', 'mimes:jpg,jpeg', 'max:'.$maxKb],
            'frames' => ['nullable', 'array', 'max:12'],
            'frames.*' => ['file', 'mimes:jpg,jpeg', 'max:'.$maxKb],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $meta = json_decode((string) $this->input('metadata'), true);
            if (! is_array($meta)) {
                return;
            }
            $check = \Illuminate\Support\Facades\Validator::make($meta, [
                'width' => ['required', 'integer', 'between:1,16384'],
                'height' => ['required', 'integer', 'between:1,16384'],
                'duration' => ['required', 'numeric', 'between:0,86400'],
                'frame_rate' => ['nullable', 'numeric', 'between:0,1000'],
                'video_codec' => ['nullable', 'string', 'max:40'],
                'audio_codec' => ['nullable', 'string', 'max:40'],
                'has_audio' => ['nullable', 'boolean'],
            ]);
            foreach ($check->errors()->messages() as $field => $messages) {
                $validator->errors()->add('metadata.'.$field, $messages[0]);
            }
        }];
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return json_decode((string) $this->input('metadata'), true);
    }
}
