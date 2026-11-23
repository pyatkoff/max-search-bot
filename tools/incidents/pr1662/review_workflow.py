"""Apply the proposal to a temporary copy of the exact original workflow and test it."""
from pathlib import Path
from tempfile import TemporaryDirectory
import hashlib
import subprocess
import sys


here = Path(__file__).resolve().parent
source = "302ba66b5739e369a4df94471c8cfe9c054dd070"
workflow = ".github/workflows/deploy.yml"
original_blob = "ea398626670c37c70f4eace3c503f813cc3d2c18"
if len(sys.argv) == 1:
    content = subprocess.check_output(["git", "show", f"{source}:{workflow}"])
elif len(sys.argv) == 2:
    content = Path(sys.argv[1]).read_bytes()
else:
    raise SystemExit("usage: review_workflow.py [original-deploy.yml]")
if hashlib.sha1(f"blob {len(content)}\0".encode() + content).hexdigest() != original_blob:
    raise SystemExit("Unexpected original workflow blob")

with TemporaryDirectory() as directory:
    target = Path(directory) / workflow
    target.parent.mkdir(parents=True)
    target.write_bytes(content)
    with (here / "deploy.proposed.patch").open("rb") as patch:
        subprocess.run(["patch", "--batch", "--fuzz=0", "-p1"], cwd=directory,
                       stdin=patch, check=True)
    subprocess.run([sys.executable, here / "check_proposal.py", target], check=True)
