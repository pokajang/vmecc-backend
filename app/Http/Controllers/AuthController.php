<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserSession;
use App\Models\LoginAttempt;
use App\Services\AssignmentAuthorizationService;
use App\Services\AuthSessionService;
use App\Support\MalaysiaStateCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private const MAX_FAILED_ATTEMPTS = 5;

    public function __construct(private readonly AuthSessionService $sessions)
    {
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);
        $credentials = [
            'email' => $data['email'],
            'password' => $data['password'],
        ];

        $ip = $request->ip();
        $ua = substr((string) $request->userAgent(), 0, 255);
        $deviceId = $request->header('X-Client-Id');
        $deviceInfo = $ua;
        $user = User::where('email', $credentials['email'])->first();

        if (! $user) {
            $this->logAttempt(null, $credentials['email'], 'Failed', 'Account not found', $ip, $ua, $deviceId, $deviceInfo);
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        if (strcasecmp((string) $user->status, 'Active') !== 0) {
            $this->logAttempt($user, $credentials['email'], 'Failed', 'Inactive account', $ip, $ua, $deviceId, $deviceInfo);
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        if ($user->locked_at) {
            $this->logAttempt($user, $credentials['email'], 'Failed', 'Account locked', $ip, $ua, $deviceId, $deviceInfo);
            throw ValidationException::withMessages([
                'email' => ['Account locked. Please contact your administrator.'],
            ]);
        }

        if (! Hash::check($credentials['password'], $user->password)) {
            $nextCount = (int) ($user->failed_login_count ?? 0) + 1;
            $update = ['failed_login_count' => $nextCount];
            if ($nextCount >= self::MAX_FAILED_ATTEMPTS) {
                $update['locked_at'] = now();
                $update['lock_reason'] = 'too_many_attempts';
                $update['locked_by'] = null;
            }
            $user->forceFill($update)->save();

            if ($nextCount >= self::MAX_FAILED_ATTEMPTS) {
                UserSession::where('user_id', $user->id)
                    ->whereNull('revoked_at')
                    ->update([
                        'logged_out_at' => now(),
                        'revoked_at' => now(),
                        'revoke_reason' => 'too_many_attempts',
                        'remember_token_hash' => null,
                        'remember_expires_at' => null,
                    ]);
            }

            $this->logAttempt($user, $credentials['email'], 'Failed', 'Invalid credentials', $ip, $ua, $deviceId, $deviceInfo);
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        $created = $this->sessions->createSession($user, $request, (bool) ($data['remember'] ?? false));
        $session = $created['session'];
        $csrfToken = $this->refreshCsrfToken($session);
        $user->forceFill([
            'last_login_at' => now(),
            'failed_login_count' => 0,
            'locked_at' => null,
            'locked_by' => null,
            'lock_reason' => null,
        ])->save();
        $this->logAttempt($user, $credentials['email'], 'Success', null, $ip, $ua, $deviceId, $deviceInfo);

        $response = $this->respondWithUser($user, $csrfToken)
            ->withCookie($this->sessions->buildSessionCookie($session->id));

        if ($created['remember_token']) {
            $response->withCookie($this->sessions->buildRememberCookie($session, $created['remember_token']));
        }

        return $response;
    }

    public function session(Request $request): JsonResponse
    {
        $sessionId = $request->cookie(AuthSessionService::SESSION_COOKIE);

        $session = $sessionId
            ? UserSession::with(['user' => fn ($query) => $query->withTrashed()])
                ->where('id', $sessionId)
                ->whereNull('logged_out_at')
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->first()
            : null;

        $restored = null;
        if (! $session) {
            $restored = $this->sessions->restoreRememberedSession($request);
            $session = $restored['session'] ?? null;
        }

        if (! $session || ! $session->user) {
            return response()->json(['message' => 'Unauthenticated'], 401)
                ->withCookie($this->sessions->forgetSessionCookie())
                ->withCookie($this->sessions->forgetRememberCookie());
        }

        $user = $session->user;
        if (! $this->sessions->isUserEligible($user)) {
            $session->forceFill([
                'logged_out_at' => now(),
                'revoked_at' => now(),
                'revoke_reason' => $this->sessions->userIneligibleReason($user),
                'remember_token_hash' => null,
                'remember_expires_at' => null,
            ])->save();

            return response()->json(['message' => 'Unauthenticated'], 401)
                ->withCookie($this->sessions->forgetSessionCookie())
                ->withCookie($this->sessions->forgetRememberCookie());
        }

        $response = $this->respondWithUser($user, $this->refreshCsrfToken($session));
        if ($restored && $restored['remember_token']) {
            $response
                ->withCookie($this->sessions->buildSessionCookie($session->id))
                ->withCookie($this->sessions->buildRememberCookie($session, $restored['remember_token']));
        }

        return $response;
    }

    public function logout(Request $request): JsonResponse
    {
        $this->sessions->invalidateSession($request->cookie(AuthSessionService::SESSION_COOKIE), 'logout');
        $this->sessions->invalidateRememberCookie($request->cookie(AuthSessionService::REMEMBER_COOKIE), 'logout');

        return response()
            ->json(['message' => 'Logged out'])
            ->withCookie($this->sessions->forgetSessionCookie())
            ->withCookie($this->sessions->forgetRememberCookie());
    }

    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            return response()->json(['message' => 'Current password is incorrect'], 422);
        }

        $user->password = Hash::make($data['password']);
        $user->setRememberToken(Str::random(60));
        $user->save();

        // Invalidate all sessions for this user (including current)
        UserSession::where('user_id', $user->id)->update([
            'logged_out_at' => now(),
            'revoked_at' => now(),
            'revoke_reason' => 'password_change',
            'remember_token_hash' => null,
            'remember_expires_at' => null,
        ]);

        return response()
            ->json(['message' => 'Password updated. Please sign in again.'])
            ->withCookie($this->sessions->forgetSessionCookie())
            ->withCookie($this->sessions->forgetRememberCookie());
    }

    private function respondWithUser(User $user, ?string $csrfToken = null): JsonResponse
    {
        $authz = app(AssignmentAuthorizationService::class);
        $loginRecords = $user->loginAttempts()
            ->latest()
            ->limit(10)
            ->get(['created_at as timestamp', 'status', 'reason', 'ip_address', 'user_agent', 'device_id', 'device_info'])
            ->map(function ($record) {
                return [
                    'timestamp' => $record->timestamp,
                    'status' => $record->status,
                    'reason' => $record->reason,
                    'ip_address' => $record->ip_address,
                    'user_agent' => $record->user_agent,
                    'device_id' => $record->device_id,
                    'device_info' => $record->device_info,
                ];
            });

        $payload = [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'ic_number' => $user->ic_number,
                'phone' => $user->phone,
                'address' => $user->address,
                'state' => MalaysiaStateCatalog::normalize($user->state),
                'profile_image_url' => $this->resolveProfileImageUrl($user->profile_image_url),
                'status' => $user->status,
                'last_login_at' => $user->last_login_at,
                'roles' => $authz->getActiveRoleNames($user)->values()->all(),
                'permissions' => $authz->getActivePermissionNames($user)->values()->all(),
                'role_assignments' => $authz->getRoleAssignmentsPayload($user),
                'emergency_contact' => $user->emergency_contact ?? null,
                'banking_info' => $user->banking_info ?? null,
                'statutory_info' => $user->statutory_info ?? null,
                'medical_info' => $user->medical_info ?? null,
                'login_records' => $loginRecords,
            ],
        ];

        if ($csrfToken !== null) {
            $payload['csrf_token'] = $csrfToken;
        }

        return response()->json($payload);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'ic_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'state' => ['sometimes', 'nullable', 'string', 'max:100', 'in:' . implode(',', MalaysiaStateCatalog::values())],
            'emergency_contact' => ['sometimes', 'array'],
            'emergency_contact.name' => ['nullable', 'string', 'max:255'],
            'emergency_contact.relationship' => ['nullable', 'string', 'max:100'],
            'emergency_contact.phone' => ['nullable', 'string', 'max:50'],
            'emergency_contact.email' => ['nullable', 'email', 'max:255'],
            'emergency_contact.address' => ['nullable', 'string', 'max:500'],
            'banking_info' => ['sometimes', 'array'],
            'banking_info.bankName' => ['nullable', 'string', 'max:255'],
            'banking_info.accountName' => ['nullable', 'string', 'max:255'],
            'banking_info.accountNumber' => ['nullable', 'string', 'max:50'],
            'statutory_info' => ['sometimes', 'array'],
            'statutory_info.epfNo' => ['nullable', 'string', 'max:100'],
            'statutory_info.perkesoNo' => ['nullable', 'string', 'max:100'],
            'statutory_info.incomeTaxNo' => ['nullable', 'string', 'max:100'],
            'medical_info' => ['sometimes', 'array'],
            'medical_info.bloodType' => ['nullable', 'string', 'max:50'],
            'medical_info.allergies' => ['nullable', 'array'],
            'medical_info.allergies.*' => ['nullable', 'string', 'max:255'],
            'medical_info.conditions' => ['nullable', 'array'],
            'medical_info.conditions.*' => ['nullable', 'string', 'max:255'],
            'medical_info.medications' => ['nullable', 'array'],
            'medical_info.medications.*' => ['nullable', 'string', 'max:255'],
            'medical_info.notes' => ['nullable', 'string', 'max:1000'],
        ]);

        if (array_key_exists('name', $data)) {
            $user->name = $data['name'];
        }
        if (array_key_exists('phone', $data)) {
            $user->phone = $data['phone'];
        }
        if (array_key_exists('ic_number', $data)) {
            $user->ic_number = $data['ic_number'];
        }
        if (array_key_exists('address', $data)) {
            $user->address = $data['address'];
        }
        if (array_key_exists('state', $data)) {
            $user->state = MalaysiaStateCatalog::normalize($data['state']);
        }

        if (array_key_exists('emergency_contact', $data)) {
            $user->setAttribute('emergency_contact', $data['emergency_contact']);
        }
        if (array_key_exists('banking_info', $data)) {
            $user->setAttribute('banking_info', $data['banking_info']);
        }
        if (array_key_exists('statutory_info', $data)) {
            $user->setAttribute('statutory_info', $data['statutory_info']);
        }
        if (array_key_exists('medical_info', $data)) {
            $user->setAttribute('medical_info', $data['medical_info']);
        }

        $user->save();

        return $this->respondWithUser($user);
    }

    public function uploadProfileImage(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $request->validate([
            'image' => ['required', 'image', 'max:2048', 'mimes:jpg,jpeg,png,webp'],
        ]);

        $disk = $this->publicUploadsDisk();
        $oldPath = trim((string) ($user->profile_image_url ?? ''));
        if ($oldPath !== '' && $this->isStoredProfileImagePath($oldPath)) {
            Storage::disk($disk)->delete($oldPath);
        }

        $path = $request->file('image')->store('profiles', ['disk' => $disk]);
        $user->forceFill(['profile_image_url' => $path])->save();

        return $this->respondWithUser($user->fresh());
    }

    public function deleteProfileImage(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $disk = $this->publicUploadsDisk();
        $oldPath = trim((string) ($user->profile_image_url ?? ''));
        if ($oldPath !== '' && $this->isStoredProfileImagePath($oldPath)) {
            Storage::disk($disk)->delete($oldPath);
        }

        $user->forceFill(['profile_image_url' => null])->save();

        return $this->respondWithUser($user->fresh());
    }

    private function refreshCsrfToken(UserSession $session): string
    {
        $token = Str::random(64);
        $session->forceFill([
            'csrf_token_hash' => hash('sha256', $token),
        ])->save();

        return $token;
    }

    private function logAttempt(?User $user, string $email, string $status, ?string $reason, ?string $ip, ?string $ua, ?string $deviceId, ?string $deviceInfo): void
    {
        LoginAttempt::create([
            'user_id' => $user?->id,
            'email' => $email,
            'status' => $status,
            'reason' => $reason,
            'ip_address' => $ip,
            'user_agent' => $ua,
            'device_id' => $deviceId,
            'device_info' => $deviceInfo,
        ]);
    }

    private function resolveProfileImageUrl(?string $value): ?string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return null;
        }

        if (str_starts_with($raw, 'http://') || str_starts_with($raw, 'https://')) {
            return $raw;
        }

        return Storage::disk($this->publicUploadsDisk())->url($raw);
    }

    private function isStoredProfileImagePath(string $value): bool
    {
        return ! str_starts_with($value, 'http://') && ! str_starts_with($value, 'https://');
    }

    private function publicUploadsDisk(): string
    {
        return (string) config('filesystems.public_uploads_disk', 'public');
    }
}
