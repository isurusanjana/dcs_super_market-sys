<?php
class AuthController extends Controller {
    protected array $public = ['login'];
    function action_login(): void {
        if (Auth::check()) redirect('home/index');
        if (is_post()) {
            [$ok, $msg] = Auth::attempt((string)input('username', ''), (string)($_POST['password'] ?? ''));
            if ($ok) { $u = Auth::user(); if ($u && $u['must_change_password']) redirect('auth/password'); redirect('home/index'); }
            flash('danger', $msg);
            redirect('auth/login');
        }
        $this->render('auth/login', ['title' => 'Sign in'], 'layout/bare');
    }
    function action_logout(): void { $this->postOnly(); Auth::logout(); session_start(); flash('success', 'You have been signed out.'); redirect('auth/login'); }
    function action_password(): void {
        $u = Auth::user(); $errors = [];
        if (is_post()) {
            $row = DB::one('SELECT password_hash FROM users WHERE id=?', [$u['id']]);
            $cur = (string)($_POST['current'] ?? ''); $new = (string)($_POST['new'] ?? ''); $conf = (string)($_POST['confirm'] ?? '');
            if (!password_verify($cur, $row['password_hash'])) $errors['current'] = 'Current password is incorrect.';
            elseif ($err = Auth::passwordError($new)) $errors['new'] = $err;
            elseif ($new !== $conf) $errors['confirm'] = 'Confirmation does not match.';
            elseif (password_verify($new, $row['password_hash'])) $errors['new'] = 'New password must differ from the current one.';
            if (!$errors) {
                DB::update('users', ['password_hash' => Auth::hashPassword($new), 'must_change_password' => 0], 'id=?', [$u['id']]);
                audit('password_change', 'user', $u['id']); session_regenerate_id(true);
                flash('success', 'Password changed.'); redirect('home/index');
            }
        }
        $this->render('auth/password', ['title' => 'Change password', 'errors' => $errors], $u['must_change_password'] ? 'layout/bare' : 'layout/main');
    }
}
