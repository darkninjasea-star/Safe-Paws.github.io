import os
import logging
from urllib.parse import quote_plus

from dotenv import load_dotenv

load_dotenv()


def _get_env(name: str, default: str) -> str:
    return os.environ.get(name, default)


class ApiConfig:
    """Configuration for the Safe Paws Python REST API."""

    API_TITLE: str = "Safe Paws Rescue & Adoption API"
    API_VERSION: str = "1.0.0"
    API_PREFIX: str = "/api/v1"

    # MySQL database settings used by the API (separate database service).
    DB_HOST: str = _get_env("PYTHON_DB_HOST", "127.0.0.1")
    DB_PORT: str = _get_env("PYTHON_DB_PORT", _get_env("DB_PORT", "3307"))
    DB_NAME: str = _get_env("PYTHON_DB_NAME", "safe_paws")
    DB_USER: str = _get_env("PYTHON_DB_USER", _get_env("DB_USER", "root"))
    DB_PASS: str = _get_env("PYTHON_DB_PASS", _get_env("DB_PASS", ""))

    # Flask server settings.
    FLASK_HOST: str = _get_env("API_HOST", "127.0.0.1")
    FLASK_PORT: str = _get_env("API_PORT", "5000")
    API_TOKEN: str = _get_env("SAFE_PAWS_API_TOKEN", "")

    @property
    def database_url(self) -> str:
        user = quote_plus(self.DB_USER)
        password = quote_plus(self.DB_PASS)
        return f"mysql+pymysql://{user}:{password}@{self.DB_HOST}:{self.DB_PORT}/{self.DB_NAME}"

    @property
    def api_url(self) -> str:
        return f"http://{self.FLASK_HOST}:{self.FLASK_PORT}{self.API_PREFIX}"


api_config = ApiConfig()


logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)
