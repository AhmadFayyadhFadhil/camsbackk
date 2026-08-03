<?php

namespace App\Http\Requests\Master;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // 1. Map 'name' to 'full_name' if 'name' is provided and 'full_name' is not
        if ($this->has('name') && !$this->has('full_name')) {
            $this->merge(['full_name' => $this->input('name')]);
        }

        // 2. Map 'cleaning_service' role to 'cs' in 'roles' array
        if ($this->has('roles') && is_array($this->input('roles'))) {
            $roles = array_map(function ($role) {
                return $role === 'cleaning_service' ? 'cs' : $role;
            }, $this->input('roles'));
            $this->merge(['roles' => $roles]);
        }

        // 3. Generate 'username' from 'email' if 'username' is not provided/empty
        if (!$this->has('username') || empty($this->input('username'))) {
            if ($this->has('email') && !empty($this->input('email'))) {
                $email = $this->input('email');
                $username = strstr($email, '@', true); // part before '@'
                
                $baseUsername = substr(preg_replace('/[^a-zA-Z0-9]/', '', $username), 0, 40);
                if (empty($baseUsername)) {
                    $baseUsername = 'user';
                }
                
                $userParam = $this->route('user') ?? $this->route('id');
                $id = $userParam instanceof \App\Models\User ? $userParam->id : $userParam;
                
                $finalUsername = $baseUsername;
                $counter = 1;
                while (\App\Models\User::where('username', $finalUsername)->where('id', '!=', $id)->exists()) {
                    $finalUsername = substr($baseUsername, 0, 40 - strlen((string)$counter)) . $counter;
                    $counter++;
                }
                
                $this->merge(['username' => $finalUsername]);
            }
        }

        // 4. Handle is_active boolean conversion
        if ($this->has('is_active')) {
            $this->merge([
                'is_active' => filter_var($this->input('is_active'), FILTER_VALIDATE_BOOLEAN)
            ]);
        }
    }

    public function rules(): array
    {
        $userParam = $this->route('user') ?? $this->route('id');
        $id = $userParam instanceof \App\Models\User ? $userParam->id : $userParam;
        $isCreate = $this->isMethod('post');

        return [
            'username' => ['required', 'string', 'max:50', 'unique:users,username,' . $id],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,' . $id],
            'password' => $isCreate
                ? ['required', 'string', 'min:8', 'regex:/^(?=.*[a-zA-Z])(?=.*\d)(?=.*[\W_]).+$/']
                : ['nullable', 'string', 'min:8', 'regex:/^(?=.*[a-zA-Z])(?=.*\d)(?=.*[\W_]).+$/'],
            'full_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'roles' => ['required', 'array'],
            'roles.*' => ['required', 'string', 'exists:roles,name'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'password.regex' => 'Password harus merupakan kombinasi dari huruf, angka, dan simbol.',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Validasi gagal.',
            'errors' => $validator->errors()->toArray()
        ], 422));
    }
}
