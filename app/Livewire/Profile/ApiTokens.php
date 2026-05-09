<?php

namespace App\Livewire\Profile;

use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class ApiTokens extends Component
{
    public string $newTokenName = '';

    /**
     * The freshly generated plaintext token, shown to the user once after
     * issuance and never persisted to Livewire state across navigation.
     * Cleared on dismiss.
     */
    public ?string $justIssuedToken = null;

    public ?string $justIssuedName = null;

    public function generate(): void
    {
        $name = trim($this->newTokenName);
        if ($name === '') {
            $this->addError('newTokenName', 'Give this token a name (e.g. "Freshdesk widget").');

            return;
        }

        $result = PersonalAccessToken::generate($this->authUser(), $name);

        $this->justIssuedToken = $result['token'];
        $this->justIssuedName = $name;
        $this->newTokenName = '';
    }

    public function revoke(int $tokenId): void
    {
        $token = PersonalAccessToken::where('id', $tokenId)
            ->where('user_id', $this->authUser()->id)
            ->first();

        if ($token === null) {
            return;
        }

        $token->revoke();
    }

    public function dismissJustIssued(): void
    {
        $this->justIssuedToken = null;
        $this->justIssuedName = null;
    }

    public function render(): View
    {
        $tokens = PersonalAccessToken::where('user_id', $this->authUser()->id)
            ->orderByDesc('created_at')
            ->get();

        return view('livewire.profile.api-tokens', [
            'tokens' => $tokens,
        ]);
    }

    private function authUser(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
