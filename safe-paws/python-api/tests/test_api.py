import unittest

from sqlalchemy import Integer, create_engine

import app as api


class RescueApiTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        api.config.API_TOKEN = "test-service-token"
        api.app.config["TESTING"] = True
        cls.app_context = api.app.app_context()
        cls.app_context.push()
        cls.mysql_engine = api.db.engines[None]
        api.db.engines[None] = create_engine("sqlite://")
        api.AdoptionApiLog.__table__.c.id.type = Integer()
        api.StrayApiLog.__table__.c.id.type = Integer()

    @classmethod
    def tearDownClass(cls):
        api.db.session.remove()
        api.db.drop_all()
        api.db.engines[None].dispose()
        api.db.engines[None] = cls.mysql_engine
        api.config.API_TOKEN = ""
        cls.app_context.pop()

    def setUp(self):
        api.db.drop_all()
        api.db.create_all()
        adopter = api.User(
            name="Test Adopter",
            email="adopter@example.test",
            password="unused-test-hash",
            role="user",
            status="active",
        )
        api.db.session.add(adopter)
        api.db.session.flush()
        shelter = api.Shelter(
            user_id=adopter.id,
            name="Test Shelter",
            address="1 Rescue Lane",
            city="Cainta",
        )
        api.db.session.add(shelter)
        api.db.session.flush()
        pet = api.Pet(
            shelter_id=shelter.id,
            name="Test Pet",
            species="dog",
            status="available",
        )
        api.db.session.add(pet)
        api.db.session.commit()
        self.adopter_id = adopter.id
        self.pet_id = pet.id
        self.client = api.app.test_client()
        self.headers = {"Authorization": "Bearer test-service-token"}

    def test_service_token_is_required(self):
        response = self.client.post("/api/v1/stray-report", json={})
        self.assertEqual(response.status_code, 401)

    def test_bearer_authorization_is_accepted(self):
        response = self.client.post(
            "/api/v1/stray-report",
            headers={**self.headers, "Authorization": "Bearer " + api.config.API_TOKEN},
            json={
                "reporter_name": "Test Reporter",
                "reporter_email": "reporter@example.test",
                "location": "Cainta town center",
                "description": "A friendly dog is waiting near the covered court.",
                "image": None,
            },
        )
        self.assertEqual(response.status_code, 201)
        self.assertEqual(api.StrayReport.query.count(), 1)

    def test_adoption_is_persisted_and_pet_is_reserved(self):
        payload = {
            "pet_id": self.pet_id,
            "applicant_id": self.adopter_id,
            "message": "Our family is ready to provide a loving home.",
            "phone": "555-0100",
            "address": "123 Family Street",
        }
        response = self.client.post(
            "/api/v1/adoption-request", headers=self.headers, json=payload
        )
        self.assertEqual(response.status_code, 201)
        self.assertEqual(api.Pet.query.filter_by(id=self.pet_id).first().status, "pending")
        self.assertEqual(api.AdoptionApplication.query.count(), 1)
        history = api.ApplicationStatusHistory.query.filter_by(application_id=1).all()
        self.assertEqual(len(history), 1)
        self.assertEqual(history[0].new_status, "submitted")

        duplicate = self.client.post(
            "/api/v1/adoption-request", headers=self.headers, json=payload
        )
        self.assertEqual(duplicate.status_code, 409)
        self.assertEqual(api.AdoptionApplication.query.count(), 1)

    def test_stray_report_without_photo_is_persisted(self):
        response = self.client.post(
            "/api/v1/stray-report",
            headers=self.headers,
            json={
                "reporter_name": "Test Reporter",
                "reporter_email": "reporter@example.test",
                "location": "Cainta town center",
                "description": "A friendly dog is waiting near the covered court.",
                "image": None,
            },
        )
        self.assertEqual(response.status_code, 201)
        self.assertEqual(response.get_json()["reference"], "SPR-000001")
        self.assertEqual(api.StrayReport.query.count(), 1)
        history = api.ReportStatusHistory.query.filter_by(report_id=1).all()
        self.assertEqual(len(history), 1)
        self.assertEqual(history[0].new_status, "new")

    def test_invalid_adoption_payload_is_rejected(self):
        response = self.client.post(
            "/api/v1/adoption-request",
            headers=self.headers,
            json={"pet_id": self.pet_id},
        )
        self.assertEqual(response.status_code, 400)

    def test_non_object_payload_is_rejected(self):
        response = self.client.post(
            "/api/v1/stray-report",
            headers={**self.headers, "Content-Type": "application/json"},
            data='["not", "an", "object"]',
        )
        self.assertEqual(response.status_code, 400)


if __name__ == "__main__":
    unittest.main()
