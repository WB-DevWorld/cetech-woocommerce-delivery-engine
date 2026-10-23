#!/usr/bin/env python3
"""Bounded private cleanup diagnostics; print only the fixed public boolean vocabulary."""
from __future__ import annotations

import json
import os
from pathlib import Path
import re
import stat
import sys

PREFIX = 'promotion_cart_cleanup_diagnostic='
FORMAT = 'cetech-n18-promotion-cleanup-diagnostic-v1'
OBSERVATION_FORMAT = 'cetech-n18-promotion-cleanup-observation-v1'
PROMOTION_KEYS = ('tracked_promotion_cleanup_restored',)
CART_KEYS = (
    'cleanup_restored', 'domain35_and_quote_operation_history_restored',
    'raw_options_restored', 'native_tax_method_session_rows_restored',
    'native_taxonomy_restored', 'owned_products_removed',
    'native_wc_objects_restored', 'all_owned_connections_retired',
    'owned_review_users_pages_removed',
)
TABLE_KEYS = (
    'rule_family_guards', 'logical_rules', 'rule_versions',
    'promotion_budget_accounts', 'promotion_budget_reservations',
)
SMALL_BOUND = 65536
STATE_BOUND = 16 * 1024 * 1024


class InvalidPrivateInput(Exception):
    pass


def unique_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise InvalidPrivateInput()
        result[key] = value
    return result


def nonfinite(_):
    raise InvalidPrivateInput()


def load_private(path, maximum):
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        info = os.fstat(descriptor)
        if not stat.S_ISREG(info.st_mode) or info.st_mode & 0o077 or not 0 < info.st_size <= maximum:
            raise InvalidPrivateInput()
        with os.fdopen(descriptor, 'rb', closefd=False) as stream:
            raw = stream.read(maximum + 1)
        if len(raw) > maximum:
            raise InvalidPrivateInput()
        return json.loads(raw.decode('utf-8'), object_pairs_hook=unique_object, parse_constant=nonfinite)
    finally:
        os.close(descriptor)


def booleans(value, keys):
    if not isinstance(value, dict) or set(value) != set(keys) or any(type(value[key]) is not bool for key in keys):
        raise InvalidPrivateInput()
    return {key: value[key] for key in keys}


def empty_report():
    return {
        'format': FORMAT,
        'status': 'FAIL',
        'phases': {key: 'not_read' for key in ('private_dir', 'command_statuses', 'promotion_receipt', 'cart_receipt', 'state', 'observation')},
        'commands': {'promotion_ok': False, 'cart_ok': False},
        'promotion': {key: None for key in PROMOTION_KEYS},
        'cart': {key: None for key in CART_KEYS},
        'owners_retired': None,
        'observation': {'owners_retired': None, 'steps_acknowledged': None, 'tables_restored': {key: None for key in TABLE_KEYS}},
    }


def command_ok(value):
    if not isinstance(value, str) or re.fullmatch(r'0|[1-9][0-9]{0,2}', value) is None or int(value) > 255:
        raise InvalidPrivateInput()
    return value == '0'


def read_phase(report, key, path, maximum, validate, optional=False):
    try:
        value = validate(load_private(path, maximum))
    except FileNotFoundError:
        report['phases'][key] = 'absent' if optional else 'missing'
    except Exception:
        report['phases'][key] = 'invalid'
    else:
        report['phases'][key] = 'valid'
        return value
    return None


def state_retirement(value):
    if not isinstance(value, dict) or not isinstance(value.get('promotion'), dict) or type(value['promotion'].get('all_owners_retired')) is not bool:
        raise InvalidPrivateInput()
    return value['promotion']['all_owners_retired']


def observation(value):
    if not isinstance(value, dict) or set(value) != {'format', 'owners_retired', 'steps_acknowledged', 'tables_restored'} or value['format'] != OBSERVATION_FORMAT:
        raise InvalidPrivateInput()
    flags = booleans({key: value[key] for key in ('owners_retired', 'steps_acknowledged')}, ('owners_retired', 'steps_acknowledged'))
    return {**flags, 'tables_restored': booleans(value['tables_restored'], TABLE_KEYS)}


def diagnose(arguments):
    report = empty_report()
    if len(arguments) != 4:
        report['phases']['command_statuses'] = 'invalid'
        return report
    directory, state, promotion_exit, cart_exit = arguments
    try:
        report['commands'] = {'promotion_ok': command_ok(promotion_exit), 'cart_ok': command_ok(cart_exit)}
        report['phases']['command_statuses'] = 'valid'
    except Exception:
        report['phases']['command_statuses'] = 'invalid'
    try:
        directory = Path(directory)
        info = directory.lstat()
        if not stat.S_ISDIR(info.st_mode) or info.st_mode & 0o077 or directory.is_symlink():
            raise InvalidPrivateInput()
        directory = directory.resolve(strict=True)
        report['phases']['private_dir'] = 'valid'
    except FileNotFoundError:
        report['phases']['private_dir'] = 'missing'
        return report
    except Exception:
        report['phases']['private_dir'] = 'invalid'
        return report

    promotion = read_phase(report, 'promotion_receipt', directory / 'promotion-cleanup.json', SMALL_BOUND, lambda value: booleans(value, PROMOTION_KEYS))
    cart = read_phase(report, 'cart_receipt', directory / 'cart-cleanup.json', SMALL_BOUND, lambda value: booleans(value, CART_KEYS))
    if promotion is not None:
        report['promotion'] = promotion
    if cart is not None:
        report['cart'] = cart
    try:
        state = Path(state)
        if state.parent.resolve(strict=True) != directory or state.name in ('promotion-cleanup.json', 'cart-cleanup.json'):
            raise InvalidPrivateInput()
    except Exception:
        report['phases']['state'] = 'invalid'
    else:
        report['owners_retired'] = read_phase(report, 'state', state, STATE_BOUND, state_retirement)
        details = read_phase(report, 'observation', Path(str(state) + '.promotion-cleanup-observation.json'), SMALL_BOUND, observation, optional=True)
        if details is not None:
            report['observation'] = details

    required = ('private_dir', 'command_statuses', 'promotion_receipt', 'cart_receipt', 'state')
    complete = all(report['phases'][key] == 'valid' for key in required)
    complete = complete and all(report['commands'].values()) and report['owners_retired'] is True
    complete = complete and all(value is True for value in (*report['promotion'].values(), *report['cart'].values()))
    if report['phases']['observation'] == 'valid':
        details = report['observation']
        complete = complete and details['owners_retired'] is True and details['steps_acknowledged'] is True and all(value is True for value in details['tables_restored'].values())
    else:
        complete = complete and report['phases']['observation'] == 'absent'
    report['status'] = 'PASS' if complete else 'FAIL'
    return report


def main(arguments):
    try:
        report = diagnose(arguments)
    except Exception:
        report = empty_report()
    print(PREFIX + json.dumps(report, separators=(',', ':'), ensure_ascii=True))
    return 0 if report['status'] == 'PASS' else 1


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
