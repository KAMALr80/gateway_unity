<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $perPage = min((int) $request->input('per_page', 15), 100);

        $query = User::withCount('orders');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($status = $request->input('approval_status')) {
            $query->where('approval_status', $status);
        }

        // Customers who sign in with their WooCommerce login.
        if ($request->boolean('woo')) {
            $query->whereNotNull('woo_password');
        }

        if ($request->input('is_admin') !== null && $request->input('is_admin') !== '') {
            $query->where('is_admin', filter_var($request->input('is_admin'), FILTER_VALIDATE_BOOLEAN));
        }

        $users = $query->latest()->paginate($perPage)->withQueryString();
        $users->getCollection()->each->append('has_woo_login');

        return Inertia::render('admin/users/index', [
            'users'   => $this->paginatedData($users),
            'filters' => $request->only(['search', 'approval_status', 'is_admin', 'woo', 'per_page']),
        ]);
    }

    public function create()
    {
        return Inertia::render('admin/users/create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'             => 'required|string|max:255',
            'email'            => 'required|email|unique:users,email',
            'phone'            => 'nullable|string|max:30',
            'password'         => 'required|string|min:8|confirmed',
            'approval_status'  => 'required|in:pending,approved,rejected',
            'is_admin'         => 'boolean',
        ]);

        $data['password'] = Hash::make($data['password']);

        $user = User::create($data);

        return redirect()->route('admin.users.show', $user)->with('success', 'User created.');
    }

    public function show(User $user)
    {
        $user->loadCount('orders');
        $user->load(['addresses', 'orders' => fn ($q) => $q->latest()->limit(5)]);
        $user->append('has_woo_login');

        return Inertia::render('admin/users/show', [
            'user' => $user,
        ]);
    }

    public function edit(User $user)
    {
        return Inertia::render('admin/users/edit', [
            'user' => $user,
        ]);
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name'            => 'required|string|max:255',
            'email'           => ['required', 'email', Rule::unique('users')->ignore($user->id)],
            'phone'           => 'nullable|string|max:30',
            'approval_status' => 'required|in:pending,approved,rejected',
            'is_admin'        => 'boolean',
        ]);

        $user->update($data);

        return redirect()->route('admin.users.show', $user)->with('success', 'User updated.');
    }

    public function updateStatus(Request $request, User $user)
    {
        $request->validate([
            'approval_status' => 'required|in:pending,approved,rejected',
        ]);

        $user->update(['approval_status' => $request->approval_status]);

        return back()->with('success', 'Status updated.');
    }
}
