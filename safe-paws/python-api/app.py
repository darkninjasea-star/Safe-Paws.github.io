"""Safe Paws Rescue & Adoption REST API.

This Flask application implements the Python REST API layer for processing
adoption requests and stray animal reports. It connects to the same MySQL
database as the PHP application and acts as the service layer for the
flowchart's adoption request and stray report processing responsibilities.

Run:
    pip install -r requirements.txt
    python app.py

Then the API is available at http://localhost:5000/api/v1/
"""

from flask import Flask, request, jsonify
from flask_sqlalchemy import SQLAlchemy
from datetime import datetime, timezone
from functools import wraps
import hmac
import logging
import re
from werkzeug.exceptions import HTTPException

from config import api_config

# --------------------------------------------------------------------------- #
# Configuration
# --------------------------------------------------------------------------- #

config = api_config
logger = logging.getLogger(__name__)


def _utcnow() -> datetime:
    return datetime.now(timezone.utc).replace(tzinfo=None)


# --------------------------------------------------------------------------- #
# App & DB setup
# --------------------------------------------------------------------------- #

app = Flask(__name__)
app.config["SQLALCHEMY_DATABASE_URI"] = config.database_url
app.config["SQLALCHEMY_TRACK_MODIFICATIONS"] = False
app.config["MAX_CONTENT_LENGTH"] = 5 * 1024 * 1024  # 5 MB

db = SQLAlchemy(app)


# --------------------------------------------------------------------------- #
# Models  (maps to existing MySQL tables)
# --------------------------------------------------------------------------- #

class User(db.Model):
    __tablename__ = "users"
    id = db.Column(db.Integer, primary_key=True)
    name = db.Column(db.String(120), nullable=False)
    email = db.Column(db.String(190), nullable=False, unique=True)
    password = db.Column(db.String(255), nullable=False)
    role = db.Column(db.Enum("user", "volunteer", "shelter", "admin"), nullable=False, default="user")
    phone = db.Column(db.String(30))
    address = db.Column(db.String(255))
    status = db.Column(db.Enum("active", "inactive", "pending_admin_approval"), nullable=False, default="active")
    created_at = db.Column(db.DateTime, default=_utcnow)
    last_login_at = db.Column(db.DateTime)

    def to_dict(self):
        return {
            "id": self.id,
            "name": self.name,
            "email": self.email,
            "role": self.role,
            "phone": self.phone,
            "address": self.address,
            "status": self.status,
            "created_at": self.created_at.isoformat() if self.created_at else None,
        }


class Shelter(db.Model):
    __tablename__ = "shelters"
    id = db.Column(db.Integer, primary_key=True)
    user_id = db.Column(db.Integer, db.ForeignKey("users.id"), nullable=False, unique=True)
    name = db.Column(db.String(160), nullable=False)
    description = db.Column(db.Text)
    address = db.Column(db.String(255), nullable=False)
    city = db.Column(db.String(100), nullable=False)
    phone = db.Column(db.String(30))
    email = db.Column(db.String(190))
    image = db.Column(db.String(255))
    status = db.Column(db.Enum("pending", "approved", "suspended"), nullable=False, default="approved")
    created_at = db.Column(db.DateTime, default=_utcnow)

    def to_dict(self):
        return {
            "id": self.id,
            "user_id": self.user_id,
            "name": self.name,
            "description": self.description,
            "address": self.address,
            "city": self.city,
            "phone": self.phone,
            "email": self.email,
            "image": self.image,
            "status": self.status,
            "created_at": self.created_at.isoformat() if self.created_at else None,
        }


class Pet(db.Model):
    __tablename__ = "pets"
    id = db.Column(db.Integer, primary_key=True)
    shelter_id = db.Column(db.Integer, db.ForeignKey("shelters.id"), nullable=False)
    name = db.Column(db.String(100), nullable=False)
    species = db.Column(db.Enum("dog", "cat", "rabbit", "other"), nullable=False, default="dog")
    breed = db.Column(db.String(100))
    sex = db.Column(db.Enum("male", "female", "unknown"), nullable=False, default="unknown")
    age = db.Column(db.String(50))
    color = db.Column(db.String(80))
    description = db.Column(db.Text)
    image = db.Column(db.String(255))
    status = db.Column(db.Enum("available", "pending", "adopted", "unavailable"), nullable=False, default="available")
    created_at = db.Column(db.DateTime, default=_utcnow)

    def to_dict(self):
        return {
            "id": self.id,
            "shelter_id": self.shelter_id,
            "name": self.name,
            "species": self.species,
            "breed": self.breed,
            "sex": self.sex,
            "age": self.age,
            "color": self.color,
            "description": self.description,
            "image": self.image,
            "status": self.status,
            "created_at": self.created_at.isoformat() if self.created_at else None,
        }


class StrayReport(db.Model):
    __tablename__ = "stray_reports"
    id = db.Column(db.Integer, primary_key=True)
    reporter_id = db.Column(db.Integer, db.ForeignKey("users.id"))
    reporter_name = db.Column(db.String(120), nullable=False)
    reporter_email = db.Column(db.String(190), nullable=False)
    phone = db.Column(db.String(30))
    animal_type = db.Column(db.String(80))
    urgency = db.Column(db.Enum("low", "normal", "high", "emergency"), nullable=False, default="normal")
    latitude = db.Column(db.Numeric(10, 7))
    longitude = db.Column(db.Numeric(10, 7))
    location = db.Column(db.String(255), nullable=False)
    description = db.Column(db.Text, nullable=False)
    image = db.Column(db.String(255))
    staff_notes = db.Column(db.Text)
    status = db.Column(
        db.Enum("new", "reviewed", "assigned", "in_progress", "rescued", "resolved", "rejected", "closed"),
        nullable=False,
        default="new",
    )
    created_at = db.Column(db.DateTime, default=_utcnow)

    def to_dict(self):
        return {
            "id": self.id,
            "reporter_id": self.reporter_id,
            "reporter_name": self.reporter_name,
            "reporter_email": self.reporter_email,
            "phone": self.phone,
            "animal_type": self.animal_type,
            "urgency": self.urgency,
            "latitude": float(self.latitude) if self.latitude else None,
            "longitude": float(self.longitude) if self.longitude else None,
            "location": self.location,
            "description": self.description,
            "image": self.image,
            "staff_notes": self.staff_notes,
            "status": self.status,
            "created_at": self.created_at.isoformat() if self.created_at else None,
        }


class RescueAssignment(db.Model):
    __tablename__ = "rescue_assignments"
    id = db.Column(db.Integer, primary_key=True)
    report_id = db.Column(db.Integer, db.ForeignKey("stray_reports.id"), nullable=False)
    volunteer_id = db.Column(db.Integer, db.ForeignKey("users.id"), nullable=False)
    shelter_id = db.Column(db.Integer, db.ForeignKey("shelters.id"))
    notes = db.Column(db.Text)
    status = db.Column(db.Enum("assigned", "in_progress", "completed", "cancelled"), nullable=False, default="assigned")
    created_at = db.Column(db.DateTime, default=_utcnow)
    updated_at = db.Column(db.DateTime, default=_utcnow, onupdate=_utcnow)

    def to_dict(self):
        return {
            "id": self.id,
            "report_id": self.report_id,
            "volunteer_id": self.volunteer_id,
            "shelter_id": self.shelter_id,
            "notes": self.notes,
            "status": self.status,
            "created_at": self.created_at.isoformat() if self.created_at else None,
            "updated_at": self.updated_at.isoformat() if self.updated_at else None,
        }


class AdoptionApplication(db.Model):
    __tablename__ = "adoption_applications"
    id = db.Column(db.Integer, primary_key=True)
    pet_id = db.Column(db.Integer, db.ForeignKey("pets.id"), nullable=False)
    applicant_id = db.Column(db.Integer, db.ForeignKey("users.id"), nullable=False)
    message = db.Column(db.Text, nullable=False)
    phone = db.Column(db.String(30))
    address = db.Column(db.String(255))
    household_type = db.Column(db.String(80))
    experience = db.Column(db.Text)
    other_pets = db.Column(db.Text)
    preferred_contact = db.Column(db.Enum("email", "phone", "either"), nullable=False, default="either")
    status = db.Column(
        db.Enum("submitted", "under_review", "approved", "rejected", "withdrawn"),
        nullable=False,
        default="submitted",
    )
    shelter_notes = db.Column(db.Text)
    created_at = db.Column(db.DateTime, default=_utcnow)
    updated_at = db.Column(db.DateTime, default=_utcnow, onupdate=_utcnow)

    def to_dict(self):
        return {
            "id": self.id,
            "pet_id": self.pet_id,
            "applicant_id": self.applicant_id,
            "message": self.message,
            "phone": self.phone,
            "address": self.address,
            "household_type": self.household_type,
            "experience": self.experience,
            "other_pets": self.other_pets,
            "preferred_contact": self.preferred_contact,
            "status": self.status,
            "shelter_notes": self.shelter_notes,
            "created_at": self.created_at.isoformat() if self.created_at else None,
            "updated_at": self.updated_at.isoformat() if self.updated_at else None,
        }


class ApplicationStatusHistory(db.Model):
    __tablename__ = "application_status_history"
    id = db.Column(db.Integer, primary_key=True)
    application_id = db.Column(db.Integer, db.ForeignKey("adoption_applications.id"), nullable=False)
    previous_status = db.Column(db.String(32))
    new_status = db.Column(db.String(32), nullable=False)
    actor_id = db.Column(db.Integer, db.ForeignKey("users.id"))
    notes = db.Column(db.Text)
    changed_at = db.Column(db.DateTime, default=_utcnow)


class ReportStatusHistory(db.Model):
    __tablename__ = "report_status_history"
    id = db.Column(db.Integer, primary_key=True)
    report_id = db.Column(db.Integer, db.ForeignKey("stray_reports.id"), nullable=False)
    previous_status = db.Column(db.String(32))
    new_status = db.Column(db.String(32), nullable=False)
    actor_id = db.Column(db.Integer, db.ForeignKey("users.id"))
    notes = db.Column(db.Text)
    changed_at = db.Column(db.DateTime, default=_utcnow)


class AdoptionApiLog(db.Model):
    __tablename__ = "adoption_api_logs"
    id = db.Column(db.BigInteger, primary_key=True)
    request_data = db.Column(db.JSON)
    response_data = db.Column(db.JSON)
    status_code = db.Column(db.Integer, nullable=False)
    api_endpoint = db.Column(db.String(255), nullable=False)
    created_at = db.Column(db.DateTime, default=_utcnow)


class StrayApiLog(db.Model):
    __tablename__ = "stray_api_logs"
    id = db.Column(db.BigInteger, primary_key=True)
    request_data = db.Column(db.JSON)
    response_data = db.Column(db.JSON)
    status_code = db.Column(db.Integer, nullable=False)
    api_endpoint = db.Column(db.String(255), nullable=False)
    created_at = db.Column(db.DateTime, default=_utcnow)


# --------------------------------------------------------------------------- #
# Helpers
# --------------------------------------------------------------------------- #

def _allowed_file(filename: str) -> bool:
    return "." in filename and filename.rsplit(".", 1)[1].lower() in ALLOWED_EXTENSIONS


def _json_error(message: str, code: int = 400):
    return jsonify({"success": False, "error": message}), code


def _service_only(handler):
    @wraps(handler)
    def wrapped(*args, **kwargs):
        supplied = request.headers.get("Authorization", "")
        expected = config.API_TOKEN or ""
        if not expected:
            return _json_error("Authentication required", 401)

        accepted = [expected, f"Bearer {expected}"]
        if not any(hmac.compare_digest(supplied, value) for value in accepted):
            return _json_error("Authentication required", 401)
        return handler(*args, **kwargs)

    return wrapped


def _text(value, maximum: int, *, required: bool = False) -> bool:
    return isinstance(value, str) and (not required or bool(value.strip())) and len(value) <= maximum


def _positive_id(value) -> bool:
    return isinstance(value, int) and not isinstance(value, bool) and value > 0


@app.errorhandler(413)
def request_too_large(_error):
    return _json_error("The request is larger than the 5 MB limit.", 413)


@app.errorhandler(Exception)
def unexpected_error(error):
    if isinstance(error, HTTPException):
        return _json_error(error.description, error.code or 500)
    logger.exception("Unhandled Safe Paws API error.")
    return _json_error("The request could not be processed.", 500)


# --------------------------------------------------------------------------- #
# Routes – Adoption requests
# --------------------------------------------------------------------------- #

@app.route(config.API_PREFIX + "/adoption-request", methods=["POST"])
@_service_only
def submit_adoption_request():
    """Submit an adoption request (called by PHP frontend)."""
    data = request.get_json(silent=True)
    if not isinstance(data, dict) or not data:
        return _json_error("Invalid or missing JSON payload", 400)

    if not _positive_id(data.get("pet_id")) or not _positive_id(data.get("applicant_id")):
        return _json_error("Pet and applicant are required.", 400)
    for field, maximum in (("message", 5000), ("phone", 30), ("address", 255)):
        if not _text(data.get(field), maximum, required=True):
            return _json_error(f"Please provide a valid {field.replace('_', ' ')}.", 400)
    for field, maximum in (("household_type", 80), ("experience", 5000), ("other_pets", 2000)):
        if not _text(data.get(field, ""), maximum):
            return _json_error(f"{field.replace('_', ' ').capitalize()} is too long.", 400)
    if len(data["message"].strip()) < 20:
        return _json_error("Please provide an application message of at least 20 characters.", 400)
    preferred_contact = data.get("preferred_contact", "either")
    if preferred_contact not in ("email", "phone", "either"):
        return _json_error("Choose a valid preferred contact method.", 400)

    applicant = User.query.filter_by(
        id=data["applicant_id"], role="user", status="active"
    ).first()
    if not applicant:
        return _json_error("An active adopter account is required.", 403)

    try:
        pet = Pet.query.filter_by(id=data["pet_id"]).with_for_update().first()
        if not pet:
            return _json_error("Pet not found", 404)
        shelter = Shelter.query.filter_by(id=pet.shelter_id, status="approved").first()
        if not shelter:
            return _json_error("This pet's shelter is not accepting applications.", 409)
        if pet.status != "available":
            return _json_error("Pet is not available for adoption", 409)

        existing = AdoptionApplication.query.filter_by(
            pet_id=pet.id, applicant_id=applicant.id
        ).first()
        if existing:
            return _json_error("You have already submitted an application for this pet.", 409)

        application = AdoptionApplication(
            pet_id=pet.id,
            applicant_id=applicant.id,
            message=data["message"].strip(),
            phone=data["phone"].strip(),
            address=data["address"].strip(),
            household_type=data.get("household_type", "").strip(),
            experience=data.get("experience", "").strip(),
            other_pets=data.get("other_pets", "").strip(),
            preferred_contact=preferred_contact,
            status="submitted",
        )
        db.session.add(application)
        pet.status = "pending"
        db.session.flush()
        db.session.add(ApplicationStatusHistory(
            application_id=application.id,
            previous_status=None,
            new_status=application.status,
            actor_id=applicant.id,
        ))
        response = {"application_id": application.id, "status": application.status}
        db.session.add(AdoptionApiLog(
            request_data={"pet_id": pet.id, "applicant_id": applicant.id},
            response_data=response,
            status_code=201,
            api_endpoint=request.path,
        ))
        db.session.commit()

        return jsonify({
            "success": True,
            **response,
            "message": "Adoption request submitted successfully.",
        }), 201

    except Exception:
        db.session.rollback()
        logger.exception("Could not save an adoption request.")
        return _json_error("The adoption request could not be saved.", 500)


@app.route(config.API_PREFIX + "/adoption-request/<int:app_id>", methods=["GET"])
@_service_only
def adoption_request_status(app_id: int):
    """Get status of a specific adoption application."""
    application = AdoptionApplication.query.filter_by(id=app_id).first()
    if not application:
        return _json_error("Application not found", 404)
    return jsonify({"success": True, "application": application.to_dict()}), 200


# --------------------------------------------------------------------------- #
# Routes – Stray reports
# --------------------------------------------------------------------------- #

@app.route(config.API_PREFIX + "/stray-report", methods=["POST"])
@_service_only
def submit_stray_report():
    """Submit a stray animal report (called by PHP frontend).

    Accepts JSON payload.  Image files should already have been uploaded by
    the PHP layer; the ``image`` key in the JSON carries the stored path.
    """
    data = request.get_json(silent=True)
    if not isinstance(data, dict) or not data:
        return _json_error("Invalid or missing JSON payload", 400)

    for field, maximum in (
        ("reporter_name", 120),
        ("reporter_email", 190),
        ("location", 255),
        ("description", 5000),
    ):
        if not _text(data.get(field), maximum, required=True):
            return _json_error(f"Please provide a valid {field.replace('_', ' ')}.", 400)
    if not re.fullmatch(r"[^@\s]+@[^@\s]+\.[^@\s]+", data["reporter_email"]):
        return _json_error("Please provide a valid reporter email.", 400)
    for field, maximum in (("phone", 30), ("animal_type", 80), ("image", 255)):
        value = data.get(field)
        if value is not None and not _text(value, maximum):
            return _json_error(f"{field.replace('_', ' ').capitalize()} is too long.", 400)
    if data.get("urgency", "normal") not in ("low", "normal", "high", "emergency"):
        return _json_error("Choose a valid urgency.", 400)
    if data.get("user_id") is not None and not _positive_id(data["user_id"]):
        return _json_error("Invalid reporter account.", 400)
    if data.get("user_id") and not User.query.filter_by(id=data["user_id"], status="active").first():
        return _json_error("Reporter account is not active.", 403)
    for field in ("latitude", "longitude"):
        if data.get(field) is not None:
            try:
                coordinate = float(data[field])
            except (TypeError, ValueError):
                return _json_error(f"Invalid {field}.", 400)
            limit = 90 if field == "latitude" else 180
            if not -limit <= coordinate <= limit:
                return _json_error(f"Invalid {field}.", 400)

    try:
        report = StrayReport(
            reporter_id=data.get("user_id"),
            reporter_name=data["reporter_name"],
            reporter_email=data["reporter_email"],
            phone=data.get("phone"),
            animal_type=data.get("animal_type"),
            urgency=data.get("urgency", "normal"),
            latitude=data.get("latitude"),
            longitude=data.get("longitude"),
            location=data["location"],
            description=data["description"],
            image=data.get("image"),
            status="new",
        )
        db.session.add(report)
        db.session.flush()
        db.session.add(ReportStatusHistory(
            report_id=report.id,
            previous_status=None,
            new_status=report.status,
            actor_id=report.reporter_id,
        ))
        response = {"report_id": report.id, "reference": f"SPR-{report.id:06d}", "status": report.status}
        db.session.add(StrayApiLog(
            request_data={"user_id": report.reporter_id, "urgency": report.urgency},
            response_data=response,
            status_code=201,
            api_endpoint=request.path,
        ))
        db.session.commit()

        return jsonify({
            "success": True,
            **response,
            "message": "Stray report submitted successfully.",
        }), 201

    except Exception:
        db.session.rollback()
        logger.exception("Could not save a stray report.")
        return _json_error("The stray report could not be saved.", 500)


@app.route(config.API_PREFIX + "/stray-report/<int:report_id>", methods=["GET"])
@_service_only
def stray_report_status(report_id: int):
    """Get status of a specific stray report."""
    report = StrayReport.query.filter_by(id=report_id).first()
    if not report:
        return _json_error("Stray report not found", 404)
    return jsonify({"success": True, "report": report.to_dict()}), 200


# --------------------------------------------------------------------------- #
# Root
# --------------------------------------------------------------------------- #

@app.route("/")
def home():
    return jsonify({
        "name": config.API_TITLE,
        "version": config.API_VERSION,
        "status": "running",
        "endpoints": {
            "adoption_request": f"{config.API_PREFIX}/adoption-request",
            "stray_report": f"{config.API_PREFIX}/stray-report",
        },
    }), 200


if __name__ == "__main__":
    app.run(host=config.FLASK_HOST, port=int(config.FLASK_PORT), debug=False)
