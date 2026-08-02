<?php

namespace Modules\CrmCore\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class CrmApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'action' => ['sometimes', 'string', 'max:80'],
            'siteId' => ['sometimes', 'integer', 'min:1'],
            'site_id' => ['sometimes', 'integer', 'min:1'],
            'allSites' => ['sometimes', 'boolean'],
            'all_sites' => ['sometimes', 'boolean'],
            'modules' => ['sometimes', 'array', 'max:80'],
            'modules.*' => ['array:slug,enabled'],
            'modules.*.slug' => ['required_with:modules', 'string', 'max:120'],
            'modules.*.enabled' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'action.string' => 'Action invalide.',
            'action.max' => 'Action trop longue.',
            'siteId.integer' => 'Site invalide.',
            'siteId.min' => 'Site invalide.',
            'site_id.integer' => 'Site invalide.',
            'site_id.min' => 'Site invalide.',
            'allSites.boolean' => 'Recherche tous sites invalide.',
            'all_sites.boolean' => 'Recherche tous sites invalide.',
            'modules.array' => 'Liste de modules invalide.',
            'modules.max' => 'Trop de modules dans la liste.',
            'modules.*.array' => 'Module invalide.',
            'modules.*.slug.required_with' => 'Module invalide.',
            'modules.*.slug.string' => 'Module invalide.',
            'modules.*.slug.max' => 'Module invalide.',
            'modules.*.enabled.boolean' => 'Activation de module invalide.',
        ];
    }

    public function action(string $default = 'bootstrap'): string
    {
        return (string) $this->query('action', $default);
    }

    /**
     * @return array<string, mixed>
     */
    public function body(): array
    {
        $json = $this->json()->all();

        if ($json !== []) {
            return $json;
        }

        return $this->request->all();
    }

    public function siteId(array $body = []): ?int
    {
        $value = $this->query('siteId')
            ?? $this->query('site_id')
            ?? $body['siteId']
            ?? $body['site_id']
            ?? null;

        $siteId = (int) $value;

        return $siteId > 0 ? $siteId : null;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function allSites(array $body = []): bool
    {
        $value = $this->query('allSites')
            ?? $this->query('all_sites')
            ?? $body['allSites']
            ?? $body['all_sites']
            ?? null;

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()
                ->json([
                    'ok' => false,
                    'error' => $validator->errors()->first(),
                ], 422, [], JSON_UNESCAPED_UNICODE)
        );
    }
}
