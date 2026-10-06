"""Run inside batch GDB; emit symbols only, never arguments or memory values."""
import json
import os
import re

import gdb


def identifier(value):
    if not isinstance(value, str):
        return None
    # C++ argument signatures, addresses, paths and arbitrary debugger output
    # are never serialized. Unknown names remain explicitly unknown.
    value = value.split("(", 1)[0]
    return value if re.fullmatch(r"[A-Za-z_][A-Za-z0-9_:.$~]{0,159}", value) else None


def module_name(value):
    if not isinstance(value, str):
        return None
    value = os.path.basename(value)
    return value if re.fullmatch(r"[A-Za-z0-9_.+-]{1,100}", value) else None


frames = []
try:
    frame = gdb.newest_frame()
    for depth in range(32):
        if frame is None:
            break
        frames.append({
            "depth": depth,
            "symbol": identifier(frame.name()),
            "module": module_name(gdb.solib_name(frame.pc())),
        })
        frame = frame.older()
    status = "symbols_captured" if any(item["symbol"] for item in frames) else "symbols_unavailable"
except gdb.error:
    status = "partial_symbols" if frames else "debugger_unavailable"

# The caller passes a newly allocated private output path; all other GDB
# output, including its startup command line, also stays in that directory.
with open(os.environ["CETECH_DE_HTTP_STACK_OUTPUT"], "x", encoding="utf-8") as output:
    json.dump({"format": "cetech-opening-native-stack-v1", "status": status,
               "frames": frames, "arguments_or_memory_published": False}, output)
    output.write("\n")
