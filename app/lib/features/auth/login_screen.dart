import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/errors.dart';
import '../../core/providers.dart';
import '../../shared/brand_logo.dart';
import '../../shared/motion.dart';
import 'auth_controller.dart';

class LoginScreen extends ConsumerStatefulWidget {
  const LoginScreen({super.key});
  @override
  ConsumerState<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends ConsumerState<LoginScreen> {
  final _form = GlobalKey<FormState>();
  final _email = TextEditingController();
  final _password = TextEditingController();
  bool _busy = false;
  bool _showPassword = false;
  String? _error;

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_form.currentState!.validate()) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await ref
          .read(authControllerProvider.notifier)
          .login(_email.text.trim(), _password.text);
    } on AppFailure catch (e) {
      setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
      _password.clear(); // never keep the password around
    }
  }

  void _useDomain(String d) {
    final local = _email.text.split('@').first.trim();
    _email.text = '$local@$d';
    _email.selection = TextSelection.collapsed(offset: local.length);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final domains = ref.watch(companyDomainsProvider).value ?? const <String>[];
    final notice = switch (ref.watch(authControllerProvider)) {
      SignedOut(:final notice) => notice,
      _ => null,
    };
    return Scaffold(
      body: DecoratedBox(
        decoration: BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
            colors: [
              scheme.primaryContainer.withValues(alpha: 0.55),
              scheme.surface,
            ],
            stops: const [0, 0.55],
          ),
        ),
        child: SafeArea(
          child: Center(
            child: SingleChildScrollView(
              padding: const EdgeInsets.all(24),
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 420),
                child: EnterAnimation(
                  child: Form(
                    key: _form,
                    child: AutofillGroup(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          const Center(child: MbunieMailLogo(size: 96)),
                          const SizedBox(height: 20),
                          Text(
                            'MbunieMail',
                            textAlign: TextAlign.center,
                            style: theme.textTheme.headlineMedium?.copyWith(
                              fontWeight: FontWeight.w700,
                            ),
                          ),
                          const SizedBox(height: 4),
                          Text(
                            'Sign in with your MbunieTech work email',
                            textAlign: TextAlign.center,
                            style: theme.textTheme.bodyLarge?.copyWith(
                              color: scheme.onSurfaceVariant,
                            ),
                          ),
                          const SizedBox(height: 28),
                          Card(
                            color: scheme.surfaceContainerLowest,
                            child: Padding(
                              padding: const EdgeInsets.all(20),
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.stretch,
                                children: [
                                  if (notice != null) ...[
                                    Container(
                                      padding: const EdgeInsets.all(12),
                                      decoration: BoxDecoration(
                                        color: scheme.secondaryContainer,
                                        borderRadius: BorderRadius.circular(12),
                                      ),
                                      child: Text(notice),
                                    ),
                                    const SizedBox(height: 16),
                                  ],
                                  TextFormField(
                                    controller: _email,
                                    keyboardType: TextInputType.emailAddress,
                                    autofillHints: const [
                                      AutofillHints.username,
                                      AutofillHints.email,
                                    ],
                                    decoration: InputDecoration(
                                      labelText: 'Work email',
                                      hintText: domains.isEmpty
                                          ? 'name@company.com'
                                          : 'name@${domains.first}',
                                      prefixIcon: const Icon(
                                        Icons.alternate_email,
                                      ),
                                    ),
                                    validator: (v) {
                                      final e = (v ?? '').trim();
                                      if (!e.contains('@'))
                                        return 'Enter your work email';
                                      if (!emailInDomains(e, domains)) {
                                        return 'Use your company email (${domains.map((d) => '@$d').join(', ')})';
                                      }
                                      return null;
                                    },
                                    textInputAction: TextInputAction.next,
                                  ),
                                  if (domains.length > 1) ...[
                                    const SizedBox(height: 10),
                                    Wrap(
                                      spacing: 6,
                                      runSpacing: 6,
                                      children: [
                                        for (final d in domains)
                                          ActionChip(
                                            label: Text('@$d'),
                                            onPressed: () => _useDomain(d),
                                            visualDensity:
                                                VisualDensity.compact,
                                          ),
                                      ],
                                    ),
                                  ],
                                  const SizedBox(height: 14),
                                  TextFormField(
                                    controller: _password,
                                    obscureText: !_showPassword,
                                    autofillHints: const [
                                      AutofillHints.password,
                                    ],
                                    decoration: InputDecoration(
                                      labelText: 'Password',
                                      helperText:
                                          'The password you use for your email',
                                      prefixIcon: const Icon(
                                        Icons.lock_outline,
                                      ),
                                      suffixIcon: IconButton(
                                        tooltip: _showPassword
                                            ? 'Hide password'
                                            : 'Show password',
                                        icon: Icon(
                                          _showPassword
                                              ? Icons.visibility_off
                                              : Icons.visibility,
                                        ),
                                        onPressed: () => setState(
                                          () => _showPassword = !_showPassword,
                                        ),
                                      ),
                                    ),
                                    validator: (v) => v != null && v.isNotEmpty
                                        ? null
                                        : 'Enter your password',
                                    onFieldSubmitted: (_) => _submit(),
                                  ),
                                  AnimatedSize(
                                    duration: Motion.fast,
                                    child: _error == null
                                        ? const SizedBox(width: double.infinity)
                                        : Padding(
                                            padding: const EdgeInsets.only(
                                              top: 14,
                                            ),
                                            child: Row(
                                              children: [
                                                Icon(
                                                  Icons.error_outline,
                                                  size: 18,
                                                  color: scheme.error,
                                                ),
                                                const SizedBox(width: 8),
                                                Expanded(
                                                  child: Text(
                                                    _error!,
                                                    style: TextStyle(
                                                      color: scheme.error,
                                                    ),
                                                    semanticsLabel:
                                                        'Error: $_error',
                                                  ),
                                                ),
                                              ],
                                            ),
                                          ),
                                  ),
                                  const SizedBox(height: 20),
                                  FilledButton(
                                    onPressed: _busy ? null : _submit,
                                    child: AnimatedSwitcher(
                                      duration: Motion.fast,
                                      child: _busy
                                          ? const SizedBox.square(
                                              dimension: 20,
                                              child: CircularProgressIndicator(
                                                strokeWidth: 2,
                                              ),
                                            )
                                          : const Text('Sign in'),
                                    ),
                                  ),
                                ],
                              ),
                            ),
                          ),
                          const SizedBox(height: 20),
                          Row(
                            mainAxisAlignment: MainAxisAlignment.center,
                            children: [
                              Icon(
                                Icons.verified_user_outlined,
                                size: 16,
                                color: scheme.onSurfaceVariant,
                              ),
                              const SizedBox(width: 6),
                              Flexible(
                                child: Text(
                                  'Encrypted · company accounts only',
                                  style: theme.textTheme.bodySmall?.copyWith(
                                    color: scheme.onSurfaceVariant,
                                  ),
                                ),
                              ),
                            ],
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
