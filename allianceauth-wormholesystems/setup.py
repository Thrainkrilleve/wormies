import re
from pathlib import Path

from setuptools import find_packages, setup


def read_version() -> str:
    """Same source as the repo-root installer: allianceauth_wormholesystems.__version__."""
    init = Path(__file__).resolve().parent / "allianceauth_wormholesystems" / "__init__.py"
    match = re.search(
        r'^__version__\s*=\s*[\'"]([^\'"]+)[\'"]',
        init.read_text(encoding="utf-8"),
        re.MULTILINE,
    )
    if match is None:
        raise RuntimeError(f"__version__ missing from {init}")
    return match.group(1)


with open("README.md", "r", encoding="utf-8") as fh:
    long_description = fh.read()

setup(
    name="allianceauth-wormholesystems",
    version=read_version(),
    author="Wormhole Systems",
    description="Wormhole Systems single sign-on & sidebar app for Alliance Auth",
    long_description=long_description,
    long_description_content_type="text/markdown",
    url="https://github.com/Thrainkrilleve/wormies",
    packages=find_packages(),
    classifiers=[
        "Environment :: Web Environment",
        "Framework :: Django",
        "Intended Audience :: Developers",
        "License :: OSI Approved :: MIT License",
        "Operating System :: OS Independent",
        "Programming Language :: Python :: 3",
        "Topic :: Internet :: WWW/HTTP",
    ],
    python_requires=">=3.10",
    install_requires=[
        "allianceauth>=4.0.0",
    ],
)
