import json
import sys
from datetime import datetime

import openpyxl

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8")


def cell_text(value):
    if value is None:
        return ""

    if isinstance(value, datetime):
        return value.strftime("%d.%m.%Y")

    return str(value).strip()


def sheet_score(sheet):
    score = 0

    for row in sheet.iter_rows(values_only=True):
        for cell in row:
            if isinstance(cell, str) and cell.strip().upper().startswith("SEI"):
                score += 1

    return score


def main():
    if len(sys.argv) < 2:
        raise SystemExit("Dosya yolu eksik.")

    with open(sys.argv[1], "rb") as source:
        workbook = openpyxl.load_workbook(source, data_only=True)
    sheet = max(workbook.worksheets, key=sheet_score)

    rows = []
    for row in sheet.iter_rows(values_only=True):
        values = [cell_text(value) for value in row]

        while values and values[-1] == "":
            values.pop()

        if any(value != "" for value in values):
            rows.append(values)

    print(json.dumps(rows, ensure_ascii=False))


if __name__ == "__main__":
    main()
