#!/usr/bin/env python3
"""Real subprocess tests for the bounded N18 cleanup diagnostic output boundary."""
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

HELPER = Path(__file__).with_name('promotion-cart-cleanup-diagnostic.py')
PREFIX = 'promotion_cart_cleanup_diagnostic='
SECRET = 'NEVER_PUBLISH_password_SQL_private_nonce'
CART_KEYS = (
    'cleanup_restored', 'domain35_and_quote_operation_history_restored', 'raw_options_restored',
    'native_tax_method_session_rows_restored', 'native_taxonomy_restored', 'owned_products_removed',
    'native_wc_objects_restored', 'all_owned_connections_retired', 'owned_review_users_pages_removed',
)
TABLE_KEYS = ('rule_family_guards', 'logical_rules', 'rule_versions', 'promotion_budget_accounts', 'promotion_budget_reservations')


class CleanupDiagnosticTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='private-cleanup-diagnostic-')
        self.directory = Path(self.temporary.name)
        self.state = self.directory / 'quote-cart-state.json'
        self.promotion = self.directory / 'promotion-cleanup.json'
        self.cart = self.directory / 'cart-cleanup.json'
        self.observation = Path(str(self.state) + '.promotion-cleanup-observation.json')
        self.write(self.state, {'password': SECRET, 'promotion': {'all_owners_retired': True, 'other_private_fact': SECRET}})
        self.write(self.promotion, {'tracked_promotion_cleanup_restored': True})
        self.write(self.cart, {key: True for key in CART_KEYS})
        self.write(self.observation, {'format': 'cetech-n18-promotion-cleanup-observation-v1', 'owners_retired': True, 'steps_acknowledged': True, 'tables_restored': {key: True for key in TABLE_KEYS}})

    def tearDown(self):
        self.temporary.cleanup()

    def write(self, path, value):
        path.write_text(json.dumps(value))
        path.chmod(0o600)

    def run_helper(self, promotion='0', cart='0', arguments=None):
        args = arguments if arguments is not None else [str(self.directory), str(self.state), promotion, cart]
        result = subprocess.run([sys.executable, str(HELPER), *args], text=True, capture_output=True, timeout=5)
        self.assertEqual('', result.stderr)
        self.assertEqual(1, len(result.stdout.splitlines()))
        self.assertTrue(result.stdout.startswith(PREFIX))
        self.assertNotIn(SECRET, result.stdout)
        self.assertNotIn(str(self.directory), result.stdout)
        report = json.loads(result.stdout[len(PREFIX):])
        self.assertEqual({'format', 'status', 'phases', 'commands', 'promotion', 'cart', 'owners_retired', 'observation'}, set(report))
        self.assertEqual({'private_dir', 'command_statuses', 'promotion_receipt', 'cart_receipt', 'state', 'observation'}, set(report['phases']))
        self.assertTrue(set(report['phases'].values()) <= {'valid', 'invalid', 'missing', 'absent', 'not_read'})
        self.assertEqual({'promotion_ok', 'cart_ok'}, set(report['commands']))
        self.assertTrue(all(type(value) is bool for value in report['commands'].values()))
        self.assertEqual({'tracked_promotion_cleanup_restored'}, set(report['promotion']))
        self.assertEqual(set(CART_KEYS), set(report['cart']))
        self.assertEqual({'owners_retired', 'steps_acknowledged', 'tables_restored'}, set(report['observation']))
        self.assertEqual(set(TABLE_KEYS), set(report['observation']['tables_restored']))
        flags = [*report['promotion'].values(), *report['cart'].values(), report['owners_retired'], report['observation']['owners_retired'], report['observation']['steps_acknowledged'], *report['observation']['tables_restored'].values()]
        self.assertTrue(all(type(value) is bool or value is None for value in flags))
        self.assertEqual(0 if report['status'] == 'PASS' else 1, result.returncode)
        return report

    def test_valid_private_receipts_and_observation_pass_without_disclosing_private_state(self):
        report = self.run_helper()
        self.assertEqual('PASS', report['status'])
        self.assertTrue(all(value == 'valid' for value in report['phases'].values()))

    def test_optional_observation_absence_keeps_known_flags_null(self):
        self.observation.unlink()
        report = self.run_helper()
        self.assertEqual('PASS', report['status'])
        self.assertEqual('absent', report['phases']['observation'])
        self.assertIsNone(report['observation']['owners_retired'])
        self.assertTrue(all(value is None for value in report['observation']['tables_restored'].values()))

    def test_each_existing_cleanup_flag_and_owner_retirement_is_required_true(self):
        for key in ('tracked_promotion_cleanup_restored', *CART_KEYS, 'all_owners_retired'):
            with self.subTest(key=key):
                self.write(self.promotion, {'tracked_promotion_cleanup_restored': key != 'tracked_promotion_cleanup_restored'})
                self.write(self.cart, {field: field != key for field in CART_KEYS})
                self.write(self.state, {'promotion': {'all_owners_retired': key != 'all_owners_retired'}})
                self.assertEqual('FAIL', self.run_helper()['status'])

    def test_nonzero_or_invalid_command_statuses_fail_even_with_true_receipts(self):
        for promotion, cart in [('1', '0'), ('0', '137'), ('00', '0'), ('256', '0'), (SECRET, '0'), ('0', '-1')]:
            with self.subTest(promotion=promotion, cart=cart):
                self.assertEqual('FAIL', self.run_helper(promotion, cart)['status'])

    def test_duplicate_unknown_and_nonboolean_receipt_fields_fail_without_echoing_input(self):
        packets = [
            '{"tracked_promotion_cleanup_restored":true,"tracked_promotion_cleanup_restored":true}',
            '{"tracked_promotion_cleanup_restored":true,"\\u0074racked_promotion_cleanup_restored":true}',
            json.dumps({'tracked_promotion_cleanup_restored': True, SECRET: SECRET}),
            json.dumps({'tracked_promotion_cleanup_restored': SECRET}),
            '{"tracked_promotion_cleanup_restored":1}',
            '{"tracked_promotion_cleanup_restored":NaN}',
            '{',
        ]
        for raw in packets:
            with self.subTest(raw=raw[:30]):
                self.promotion.write_text(raw)
                report = self.run_helper()
                self.assertEqual('FAIL', report['status'])
                self.assertEqual('invalid', report['phases']['promotion_receipt'])
                self.assertIsNone(report['promotion']['tracked_promotion_cleanup_restored'])

    def test_missing_required_private_files_fail(self):
        for path, phase in ((self.promotion, 'promotion_receipt'), (self.cart, 'cart_receipt'), (self.state, 'state')):
            with self.subTest(phase=phase):
                saved = path.read_bytes()
                path.unlink()
                report = self.run_helper()
                self.assertEqual('FAIL', report['status'])
                self.assertEqual('missing', report['phases'][phase])
                path.write_bytes(saved)
                path.chmod(0o600)

    def test_symlinks_nonprivate_files_and_oversize_packets_refuse(self):
        original = self.promotion.read_bytes()
        foreign = self.directory / 'foreign-private.json'
        self.write(foreign, {'tracked_promotion_cleanup_restored': True, 'private': SECRET})
        self.promotion.unlink()
        self.promotion.symlink_to(foreign)
        self.assertEqual('invalid', self.run_helper()['phases']['promotion_receipt'])
        self.promotion.unlink()
        self.promotion.write_bytes(original)
        self.promotion.chmod(0o644)
        self.assertEqual('invalid', self.run_helper()['phases']['promotion_receipt'])
        self.promotion.chmod(0o600)
        self.promotion.write_text(' ' * 65537)
        self.assertEqual('invalid', self.run_helper()['phases']['promotion_receipt'])

    def test_state_missing_nonboolean_duplicate_and_oversize_owner_flags_refuse(self):
        for raw in ('{}', '{"promotion":{"all_owners_retired":1}}', '{"promotion":{"all_owners_retired":true,"all_owners_retired":false}}', ' ' * (16 * 1024 * 1024 + 1)):
            with self.subTest(size=len(raw)):
                self.state.write_text(raw)
                report = self.run_helper()
                self.assertEqual('FAIL', report['status'])
                self.assertEqual('invalid', report['phases']['state'])
                self.assertIsNone(report['owners_retired'])

    def test_observation_foreign_keys_nonboolean_wrong_format_and_false_tables_fail(self):
        baseline = json.loads(self.observation.read_text())
        packets = [
            {**baseline, SECRET: SECRET}, {**baseline, 'format': SECRET},
            {**baseline, 'owners_retired': 'true'}, {**baseline, 'steps_acknowledged': False},
            {**baseline, 'tables_restored': {**baseline['tables_restored'], SECRET: True}},
            {**baseline, 'tables_restored': {**baseline['tables_restored'], 'rule_versions': False}},
        ]
        for value in packets:
            with self.subTest(value=str(value)[:50]):
                self.write(self.observation, value)
                self.assertEqual('FAIL', self.run_helper()['status'])

    def test_foreign_state_path_private_directory_symlink_and_malformed_cli_fail(self):
        self.assertEqual('FAIL', self.run_helper(arguments=[str(self.directory), '/unowned/' + SECRET, '0', '0'])['status'])
        self.assertEqual('FAIL', self.run_helper(arguments=[SECRET])['status'])
        alias = self.directory / 'directory-alias'
        alias.symlink_to(self.directory, target_is_directory=True)
        self.assertEqual('invalid', self.run_helper(arguments=[str(alias), str(self.state), '0', '0'])['phases']['private_dir'])

    def test_fifo_is_rejected_without_blocking(self):
        self.promotion.unlink()
        os.mkfifo(self.promotion, 0o600)
        self.assertEqual('invalid', self.run_helper()['phases']['promotion_receipt'])


if __name__ == '__main__':
    unittest.main()
