import 'package:flutter/material.dart';

import 'pin_widgets.dart';

/// Choose a 4–6 digit PIN, then type it again. Pops with the PIN, or null.
class SetPinScreen extends StatefulWidget {
  const SetPinScreen({super.key, this.title = 'Set a PIN'});
  final String title;

  static const minLength = 4;
  static const maxLength = 6;

  @override
  State<SetPinScreen> createState() => _SetPinScreenState();
}

class _SetPinScreenState extends State<SetPinScreen> {
  String _pin = '';
  String? _first;
  int _shake = 0;
  String? _error;

  bool get _confirming => _first != null;

  void _digit(String d) {
    final max = _confirming ? _first!.length : SetPinScreen.maxLength;
    if (_pin.length >= max) return;
    setState(() {
      _pin += d;
      _error = null;
    });
    if (_pin.length == max) _next();
  }

  void _next() {
    if (!_confirming) {
      if (_pin.length < SetPinScreen.minLength) return;
      if (_weak(_pin)) {
        setState(() {
          _shake++;
          _error = 'Too easy to guess. Avoid repeats like 1111 or 1234.';
          _pin = '';
        });
        return;
      }
      setState(() {
        _first = _pin;
        _pin = '';
      });
      return;
    }
    if (_pin == _first) {
      Navigator.pop(context, _pin);
    } else {
      setState(() {
        _shake++;
        _error = 'The PINs did not match. Start again.';
        _first = null;
        _pin = '';
      });
    }
  }

  static bool _weak(String pin) {
    if (pin.split('').toSet().length == 1) return true; // 1111
    const runs = '01234567890';
    const down = '09876543210';
    return runs.contains(pin) || down.contains(pin); // 1234, 4321
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final length = _confirming
        ? _first!.length
        : (_pin.length < SetPinScreen.minLength
              ? SetPinScreen.minLength
              : _pin.length);
    return Scaffold(
      appBar: AppBar(title: Text(widget.title)),
      body: Center(
        child: SingleChildScrollView(
          padding: const EdgeInsets.symmetric(vertical: 24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(
                _confirming ? Icons.verified_user_outlined : Icons.lock_outline,
                size: 48,
                color: theme.colorScheme.primary,
              ),
              const SizedBox(height: 16),
              Text(
                _confirming ? 'Type it again' : 'Choose a 4–6 digit PIN',
                style: theme.textTheme.titleLarge,
              ),
              const SizedBox(height: 8),
              Text(
                _error ??
                    (_confirming
                        ? 'To make sure it is right.'
                        : 'You will use it to open MbunieMail.'),
                textAlign: TextAlign.center,
                style: TextStyle(
                  color: _error != null
                      ? theme.colorScheme.error
                      : theme.colorScheme.onSurfaceVariant,
                ),
              ),
              const SizedBox(height: 28),
              PinDots(length: length, filled: _pin.length, shakeSignal: _shake),
              const SizedBox(height: 32),
              PinPad(
                onDigit: _digit,
                onBackspace: () {
                  if (_pin.isNotEmpty) {
                    setState(() => _pin = _pin.substring(0, _pin.length - 1));
                  }
                },
              ),
              const SizedBox(height: 12),
              if (!_confirming)
                FilledButton(
                  onPressed: _pin.length >= SetPinScreen.minLength
                      ? _next
                      : null,
                  child: const Text('Continue'),
                ),
            ],
          ),
        ),
      ),
    );
  }
}
