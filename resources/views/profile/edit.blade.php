@extends('layouts.app')
@section('title', 'Edit Profile - JOTIFY')

@section('header')
<div class="flex items-center gap-3">
    <a href="/profile" class="p-2 rounded-lg hover:bg-hover transition-colors">
        <span class="material-icons-outlined">arrow_back</span>
    </a>
    <h1 class="text-lg font-bold">Edit Profile</h1>
</div>
@endsection

@section('content')
<div class="max-w-md mx-auto space-y-5 py-6">

    <form action="/profile" method="POST" enctype="multipart/form-data"
          class="bg-card rounded-2xl border border-border">
        @csrf
        @method('PUT')

        {{-- ── Avatar + Name header ─────────────────────────────────────────── --}}
        <div class="flex flex-col items-center border-b border-border"
             style="padding: 40px 24px 28px; border-radius: 1rem 1rem 0 0; background: linear-gradient(180deg, var(--color-sidebar) 0%, var(--color-card) 100%);">

            {{-- Avatar --}}
            <div class="relative group cursor-pointer"
                 onclick="document.getElementById('avatar-file-input').click()"
                 title="Click to change avatar">
                <img id="avatar-preview"
                     src="{{ $user->avatar_url }}"
                     alt="Avatar"
                     class="object-cover shadow-xl transition-all duration-200"
                     style="width:112px; height:112px; border-radius:50%; border:3px solid var(--accent-border); display:block;">

                {{-- Hover overlay --}}
                <div class="absolute inset-0 rounded-full bg-black/50 opacity-0 group-hover:opacity-100 transition-opacity duration-200 flex items-center justify-center">
                    <span class="material-icons-outlined text-white text-2xl">photo_camera</span>
                </div>

                {{-- Edit badge --}}
                <div style="position:absolute; bottom:3px; right:3px; width:26px; height:26px; border-radius:50%; background:#3b82f6; border:2px solid var(--color-card); display:flex; align-items:center; justify-content:center; box-shadow:0 2px 6px rgba(0,0,0,0.4);">
                    <span class="material-icons-outlined text-white" style="font-size:13px;">edit</span>
                </div>

                <input type="file" id="avatar-file-input" name="avatar"
                       accept="image/*" class="hidden" onchange="previewAvatar(this)">
            </div>

            {{-- Name & Email --}}
            <div class="mt-6 text-center space-y-1.5">
                <p class="text-xl font-bold tracking-tight">{{ $user->display_name }}</p>
                <p class="text-sm text-muted">{{ $user->email }}</p>
            </div>
        </div>

        {{-- ── Fields ──────────────────────────────────────────────────────── --}}
        <div class="space-y-6" style="padding: 36px 28px 40px;">

            <div>
                <label class="block text-xs font-semibold text-muted uppercase tracking-wider mb-2">
                    Display Name
                </label>
                <input type="text" name="display_name"
                       value="{{ old('display_name', $user->display_name) }}"
                       class="form-input w-full" placeholder="Your name" required>
                @error('display_name')
                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label class="block text-xs font-semibold text-muted uppercase tracking-wider mb-2">
                    Email Address
                </label>
                <input type="email" name="email"
                       value="{{ old('email', $user->email) }}"
                       class="form-input w-full bg-muted text-muted cursor-not-allowed"
                       placeholder="your@email.com" readonly>
                @error('email')
                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                @enderror
            </div>

            <button type="submit" class="btn-primary w-full mt-2">
                <span class="material-icons-outlined text-lg">save</span>
                Save Changes
            </button>
        </div>
    </form>

    {{-- ── Remove avatar ────────────────────────────────────────────────── --}}
    @if($user->avatar)
    <form action="/profile/avatar" method="POST"
          class="bg-card rounded-2xl border border-border px-6 py-4 flex items-center justify-between">
        @csrf
        @method('DELETE')
        <div>
            <p class="text-sm font-medium">Remove Avatar</p>
            <p class="text-xs text-muted mt-0.5">Revert to default initials</p>
        </div>
        <button type="submit" class="btn-danger text-sm flex items-center gap-1.5">
            <span class="material-icons-outlined text-base">delete</span>
            Remove
        </button>
    </form>
    @endif

<script>
function previewAvatar(input) {
    if (!input.files || !input.files[0]) return;
    document.getElementById('avatar-preview').src = URL.createObjectURL(input.files[0]);
}

(function () {
    const form = document.querySelector('form[action="/profile"]');
    if (!form) return;

    // ── When online: persist current profile data to IDB so offline fallback works ──
    if (navigator.onLine && window.saveProfileToIDB) {
        window.saveProfileToIDB({
            display_name: form.querySelector('[name="display_name"]')?.value ?? '',
            email:        form.querySelector('[name="email"]')?.value ?? '',
            avatar_url:   document.getElementById('avatar-preview')?.src ?? '',
        });
    }

    // ── When offline: fill form from IDB cache ────────────────────────────────
    if (!navigator.onLine && window.getProfileFromIDB) {
        window.getProfileFromIDB().then(profile => {
            if (!profile) return;
            const nameInput  = form.querySelector('[name="display_name"]');
            const emailInput = form.querySelector('[name="email"]');
            if (nameInput  && !nameInput.value)  nameInput.value  = profile.display_name ?? '';
            if (emailInput && !emailInput.value) emailInput.value = profile.email ?? '';
            const avatar = document.getElementById('avatar-preview');
            if (avatar && profile.avatar_url) avatar.src = profile.avatar_url;
        });
    }

    // ── Intercept submit when offline ─────────────────────────────────────────
    form.addEventListener('submit', async function (e) {
        if (navigator.onLine) return; // let browser handle it normally

        e.preventDefault();

        const display_name = form.querySelector('[name="display_name"]')?.value ?? '';
        const email        = form.querySelector('[name="email"]')?.value ?? '';

        // Store avatar as data URL if the user picked a new one
        let avatarDataUrl = null;
        const avatarInput = document.getElementById('avatar-file-input');
        if (avatarInput?.files?.[0]) {
            avatarDataUrl = await new Promise(resolve => {
                const reader = new FileReader();
                reader.onload = () => resolve(reader.result);
                reader.readAsDataURL(avatarInput.files[0]);
            });
        }

        if (window.queueProfileUpdate) {
            await window.queueProfileUpdate({ display_name, email, avatarDataUrl });
            // Update the cached profile too so the page reflects the new name
            if (window.saveProfileToIDB) {
                await window.saveProfileToIDB({
                    display_name,
                    email,
                    avatar_url: avatarDataUrl ?? document.getElementById('avatar-preview')?.src ?? '',
                });
            }
        }

        if (window.showToast) {
            window.showToast('You are offline. Changes saved locally and will sync when back online.', 'info');
        }
    });

    // ── On reconnect: sync queued profile update ──────────────────────────────
    window.addEventListener('online', async () => {
        if (!window.getProfileQueue || !window.clearProfileQueue) return;
        const pending = await window.getProfileQueue();
        if (!pending) return;

        try {
            const formData = new FormData();
            formData.append('_method', 'PUT');
            formData.append('_token', window.csrfToken ?? document.querySelector('meta[name="csrf-token"]')?.content ?? '');
            formData.append('display_name', pending.display_name ?? '');
            formData.append('email', pending.email ?? '');

            // If user chose a new avatar, convert data URL back to Blob
            if (pending.avatarDataUrl) {
                const res = await fetch(pending.avatarDataUrl);
                const blob = await res.blob();
                formData.append('avatar', blob, 'avatar.jpg');
            }

            const response = await fetch('/profile', { method: 'POST', body: formData });
            if (response.ok) {
                await window.clearProfileQueue();
                if (window.showToast) window.showToast('Profile synced successfully!', 'success');
            }
        } catch (err) {
            console.warn('[Profile] Sync failed, will retry on next reconnect:', err);
        }
    });
})();
</script>

</div>

@endsection
