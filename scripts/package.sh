#!/usr/bin/env bash
set -euo pipefail
PROJECT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
mkdir -p "$PROJECT_DIR/dist"
cd "$PROJECT_DIR"
python3 - <<'PY'
from pathlib import Path
from zipfile import ZipFile, ZIP_DEFLATED
import shutil
source = Path('soulmarke-forms')
target = Path('dist/soulmarke-forms.zip')
with ZipFile(target, 'w', ZIP_DEFLATED) as archive:
    for path in sorted(source.rglob('*')):
        if path.is_file():
            archive.write(path, path.as_posix())
shutil.copyfile(target, 'soulmarke-forms.zip')
print(f'Packaged {len(list(source.rglob("*")))} source entries into {target} and soulmarke-forms.zip')
PY
