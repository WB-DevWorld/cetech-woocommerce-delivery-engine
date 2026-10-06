"""Batch GDB: allowlisted location metadata, never arguments or memory values."""
import json
import os
import re

import gdb


def identifier(value):
    if not isinstance(value, str):
        return None
    value = value.split("(", 1)[0]
    return value if re.fullmatch(r"[A-Za-z_][A-Za-z0-9_:.$~]{0,159}", value) else None


def module_name(value):
    if not isinstance(value, str):
        return None
    value = os.path.basename(value)
    return value if re.fullmatch(r"[A-Za-z0-9_.+-]{1,100}", value) else None


def build_id(value):
    return value.lower() if isinstance(value, str) and re.fullmatch(r"[a-fA-F0-9]{16,128}", value) else None


def owner(objfile):
    return getattr(objfile, "owner", None) or objfile


def symbol_validation():
    expected = os.path.realpath(os.environ.get("CETECH_DE_HTTP_DEBUG_EXECUTABLE", ""))
    main = next((obj for obj in gdb.objfiles() if not getattr(obj, "owner", None)
                 and os.path.realpath(obj.filename) == expected), None)
    main_id = build_id(getattr(main, "build_id", None))
    debug_ids = [build_id(getattr(obj, "build_id", None)) for obj in gdb.objfiles()
                 if getattr(obj, "owner", None) == main and main is not None]
    zend = gdb.lookup_global_symbol("zend_execute")
    full_symbol = zend is not None and zend.symtab is not None and owner(zend.symtab.objfile) == main
    try:
        type_metadata = gdb.lookup_type("zend_execute_data").sizeof > 0
    except gdb.error:
        type_metadata = False
    matching_debug = main_id is not None and main_id in debug_ids
    return {"status": "PASS" if main is not None and matching_debug and full_symbol and type_metadata else "FAIL",
            "exact_executable_loaded": main is not None, "executable_module": module_name(getattr(main, "filename", None)),
            "executable_build_id": main_id, "matching_separate_debug_build_id": main_id if matching_debug else None,
            "zend_execute_full_symbol": full_symbol, "zend_execute_data_type": type_metadata}


def core_mappings():
    # Raw mapping text and addresses are used internally only, including for
    # the main executable when no source symbol covers the instruction.
    try:
        text = gdb.execute("info proc mappings", to_string=True)
    except gdb.error:
        return []
    mappings = []
    for line in text.splitlines():
        match = re.match(r"\s*(0x[0-9a-fA-F]+)\s+(0x[0-9a-fA-F]+)\s+0x[0-9a-fA-F]+\s+0x[0-9a-fA-F]+\s+(.*)", line)
        if match:
            # GDB releases differ on whether a permissions column is present.
            tail = re.sub(r"^[rwxps-]{4,5}\s+", "", match[3].strip())
            mappings.append((int(match[1], 16), int(match[2], 16), tail))
    return mappings


def location(frame, mappings):
    pc = frame.pc()
    obj = None
    sal = frame.find_sal()
    progspace = gdb.current_progspace()
    if hasattr(progspace, "objfile_for_address"):
        obj = progspace.objfile_for_address(pc)
    if obj is None and sal.symtab is not None:
        obj = sal.symtab.objfile
    obj = owner(obj) if obj is not None else None
    mapped = next((path for start, end, path in mappings if start <= pc < end), None)
    filename = getattr(obj, "filename", None) or gdb.solib_name(pc) or mapped
    source = module_name(sal.symtab.filename) if sal.symtab is not None else None
    return {"module": module_name(filename),
            "mapping_known": obj is not None or mapped is not None or filename is not None,
            "source_file": source, "source_line": sal.line if source and 0 < sal.line <= 1000000 else None}


frames = []
validation = {"status": "FAIL"}
try:
    validation = symbol_validation()
    if os.environ.get("CETECH_DE_HTTP_DEBUG_MODE") == "preflight":
        status = "preflight_pass" if validation["status"] == "PASS" else "preflight_fail"
    else:
        mappings = core_mappings()
        frame = gdb.newest_frame()
        for depth in range(32):
            if frame is None:
                break
            frames.append({"depth": depth, "symbol": identifier(frame.name()), **location(frame, mappings)})
            frame = frame.older()
        status = "symbols_captured" if any(item["symbol"] for item in frames) else "symbols_unavailable"
except gdb.error:
    status = "partial_symbols" if frames else "debugger_unavailable"

# The caller passes a newly allocated private output path; all other GDB
# output, including its startup command line, also stays in that directory.
with open(os.environ["CETECH_DE_HTTP_STACK_OUTPUT"], "x", encoding="utf-8") as output:
    json.dump({"format": "cetech-opening-native-stack-v2", "status": status,
               "symbol_validation": validation, "frames": frames,
               "arguments_or_memory_published": False}, output)
    output.write("\n")
